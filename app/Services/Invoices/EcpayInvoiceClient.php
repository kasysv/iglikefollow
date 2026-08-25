<?php

namespace App\Services\Invoices;

use App\DTO\EcpayInvoiceResponse;
use App\DTO\InvoiceFailureCode;
use App\Models\IntegrationSetting;
use Carbon\CarbonImmutable;
use Ecpay\Sdk\Services\AesService;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * The HTTP half of ECPay invoicing: envelope, AES, and strict reading.
 *
 * ⛔ Nothing here is stored and nothing here throws into the caller. Every
 * outcome is one of three typed answers, because the caller's decision — issue,
 * refuse, or wait for a human — depends entirely on whether an invoice might
 * exist at ECPay, and an exception carries that information badly.
 *
 * ⛔ The cryptography is the official SDK's, and it is fed the way the SDK
 * documents it: `encrypt()` takes an **array** and does its own
 * `json_encode → urlencode → AES` internally, and `decrypt()` returns an
 * **array** having already undone all three. An earlier version JSON-encoded
 * the payload before handing it over and `json_decode`d the result afterwards.
 * That produced a JSON string wrapped in JSON — a different ciphertext from the
 * one ECPay expects — and would have cast their real array reply to the string
 * "Array". The fixtures used the same wrong flow on both sides, so the tests
 * passed while the integration could never have worked against the real
 * provider.
 *
 * Issue and GetIssue share the outer envelope and the cipher, and nothing else:
 * their success payloads use entirely different field names, so they get
 * entirely separate parsers.
 */
class EcpayInvoiceClient
{
    private const TIMEOUT = 20;

    private const REVISION = '3.0.0';

    /**
     * Ask ECPay to issue an invoice.
     *
     * @param  array<string, mixed>  $payload  built from the order snapshot
     */
    public function issue(IntegrationSetting $setting, array $payload): EcpayInvoiceResponse
    {
        $endpoint = InvoiceSandboxGuard::issueEndpoint();

        if ($endpoint === null) {
            return EcpayInvoiceResponse::rejected(InvoiceFailureCode::local('ISSUE', 'CONFIG'));
        }

        $inner = $this->call($setting, $endpoint, $payload, 'ISSUE');

        return $inner instanceof InvoiceFailureCode
            ? EcpayInvoiceResponse::uncertain($inner)
            : $this->readIssue($inner);
    }

    /**
     * Read-only: has an invoice already been issued for this RelateNumber?
     *
     * ⛔ Used once after an unclear Issue, and only to find positive proof. A
     * "not found" answer is not permission to issue again — their system may
     * simply not have caught up, and a second issue is a second tax document.
     */
    public function query(IntegrationSetting $setting, string $relateNumber): EcpayInvoiceResponse
    {
        $endpoint = InvoiceSandboxGuard::queryEndpoint();

        if ($endpoint === null) {
            return EcpayInvoiceResponse::uncertain(InvoiceFailureCode::local('QUERY', 'CONFIG'));
        }

        $merchantId = (string) $setting->identifier;

        $inner = $this->call($setting, $endpoint, [
            'MerchantID' => $merchantId,
            'RelateNumber' => $relateNumber,
        ], 'QUERY');

        return $inner instanceof InvoiceFailureCode
            ? EcpayInvoiceResponse::uncertain($inner)
            : $this->readQuery($inner, $merchantId, $relateNumber);
    }

    /**
     * Send one request and return the decrypted inner payload, or a failure code.
     *
     * ⭐ 回傳型別本身就是本輪的修正。
     *
     * ⛔ 舊版一律回 `null`：逾時、非 2xx、商店代號不符、解密失敗、body 不是
     * JSON——全部同一個值。呼叫端因此只能一律當成「不知道」，Owner 在後台也
     * 只看得到 `UNKNOWN`，無從分辨是憑證、傳輸、開立欄位還是查詢解析問題，
     * 只能靠再送一次真實 Issue 去猜。而每一次盲測都可能開出一張真的發票。
     *
     * 現在每一層各自回傳自己的 `InvoiceFailureCode`。⛔ 語意完全沒有放寬：
     * 呼叫端看到 `InvoiceFailureCode` 仍然一律視為 uncertain，只是現在知道
     * 是在哪一層、對方給了什麼數字。
     *
     * @param  array<string, mixed>  $payload
     * @param  string  $phase  `ISSUE` 或 `QUERY`
     * @return array<string, mixed>|InvoiceFailureCode
     */
    private function call(
        IntegrationSetting $setting,
        string $endpoint,
        array $payload,
        string $phase,
    ): array|InvoiceFailureCode {
        $hashKey = $setting->secret('HashKey');
        $hashIv = $setting->secret('HashIV');
        $merchantId = (string) $setting->identifier;

        if ($hashKey === null || $hashIv === null || $merchantId === '') {
            return InvoiceFailureCode::local($phase, 'CONFIG');
        }

        $aes = new AesService($hashKey, $hashIv);

        $envelope = [
            'MerchantID' => $merchantId,
            'RqHeader' => [
                // ⛔ 由可覆寫的 clock 產生，方便測試；⛔ 不寫入 log。
                'Timestamp' => $this->now()->getTimestamp(),
                'Revision' => self::REVISION,
            ],
            // ⛔ 直接傳 array：SDK 自己做 json_encode → urlencode → AES。
            'Data' => $aes->encrypt($payload),
        ];

        try {
            $response = Http::asJson()
                ->acceptJson()
                ->timeout(self::TIMEOUT)
                ->post($endpoint, $envelope);
        } catch (Throwable) {
            // ⛔ 逾時或連線失敗＝結果不明：對方可能已經開出發票了。
            return InvoiceFailureCode::local($phase, 'HTTP');
        }

        // ⛔ 非 2xx 時 body 不可採信，即使裡面寫著成功。
        if (! $response->successful()) {
            return InvoiceFailureCode::local($phase, 'HTTP');
        }

        $json = $response->json();

        if (! is_array($json)) {
            return InvoiceFailureCode::local($phase, 'JSON');
        }

        /*
         * 回應必須來自我們自己的商店代號。
         *
         * ⭐ R2：改用欄位專用的封閉正規化。綠界的 MerchantID 是純數字，若對方
         * 在 JSON 中以數字回傳，`json_decode` 會給 int，舊版的嚴格 `!==` 字串
         * 比較就必然不相等——這是 2026-08-26 live 診斷回報
         * `ISSUE_IDENTITY|QUERY_IDENTITY` 的**最強候選子原因**。
         *
         * ⛔ identity 驗證本身沒有被移除或放寬到「不驗」：只多接受「同一個
         * 數字的 int 表示」，前導零不補、不 trim、負號與科學記號一律拒絕。
         */
        if (! EcpayScalar::merchantMatches($json['MerchantID'] ?? null, $merchantId)) {
            // ⛔ 只記「商店代號不符」這件事；⛔ 不記任何一方的實際值。
            return InvoiceFailureCode::local($phase, 'MERCHANT');
        }

        /*
         * ⭐ 本輪事故的根因之一。
         *
         * ⛔ 舊版是 `!== 1`（嚴格、只接受 int）。官方文件把 `TransCode` 標為
         * Int，但實際 live 回應常是字串 `"1"`，於是一個真正成功的回應被判成
         * 失敗——Owner 連續兩張訂單「綠界端實際已開立、本站顯示開立失敗」
         * 就是這個 false negative。
         *
         * ⛔ 放寬的**只有** int 與純數字字串的差別：`EcpayScalar` 仍然拒絕
         * bool、float、array、object、空值與超長值。看不懂就不算成功。
         */
        if (! EcpayScalar::equalsInt($json['TransCode'] ?? null, 1)) {
            /*
             * ⭐ 帶出對方的 outer 數字碼。
             *
             * ⛔ `numeric()` 只接受整數或純數字字串；字串、array、超長或非數字
             * 值一律降級為固定的 `{phase}_TRANS`，不得把無法驗證的內容拼進 code。
             */
            return InvoiceFailureCode::numeric($phase, 'TRANS', $json['TransCode'] ?? null);
        }

        $data = $json['Data'] ?? null;

        if (! is_string($data) || trim($data) === '') {
            return InvoiceFailureCode::local($phase, 'SHAPE');
        }

        try {
            // ⛔ SDK 直接回 array，已完成 AES → urldecode → json_decode。
            $inner = $aes->decrypt($data);
        } catch (Throwable) {
            return InvoiceFailureCode::local($phase, 'DECRYPT');
        }

        return is_array($inner) ? $inner : InvoiceFailureCode::local($phase, 'DECRYPT');
    }

    /**
     * The Issue success payload.
     *
     * ⛔ A success code with a field we cannot read is not a success. The date
     * in particular must parse in ECPay's own documented format: a record whose
     * issue date we had to invent would be out of step with the tax authority's
     * copy, and nobody would notice for months.
     *
     * @param  array<string, mixed>  $inner
     */
    private function readIssue(array $inner): EcpayInvoiceResponse
    {
        /*
         * ⭐ 同一個根因：`RtnCode` 官方標為 Int，live 常回字串 `"1"`。
         *
         * ⛔ 舊版的 `!== 1` 會把真正開立成功的回應丟進 uncertain，接著
         * GetIssue 又因為同樣的字串問題查不回來，最後落成「開立失敗」——
         * 而綠界那邊其實已經開出一張真的發票。
         */
        if (! EcpayScalar::equalsInt($inner['RtnCode'] ?? null, 1)) {
            /*
             * ⛔ 非成功碼一律不猜「發票有沒有開出來」——綠界的錯誤碼會持續
             * 新增，「我們看不懂這個碼」不等於「發票沒開」，所以結果仍是不確定。
             *
             * ⭐ 但那個數字本身現在會被保存下來。Owner 需要看到的正是它：
             * `ISSUE_RTN=10000001` 才分得出是欄位錯、憑證錯還是重複開立。
             */
            return EcpayInvoiceResponse::uncertain(
                InvoiceFailureCode::numeric('ISSUE', 'RTN', $inner['RtnCode'] ?? null)
            );
        }

        /*
         * ⭐ R1：欄位各自以官方 shape 驗證，並各自留下自己的代碼。
         *
         * ⛔ 初版共用一個泛用 `identifier()`（接受任意非空字串與任意整數），
         * 會讓 `InvoiceNo=1234` 被當成合法發票號碼、`RandomNumber=123` 被存成
         * 少一碼的 `"123"`。發票號碼與隨機碼是稅務憑證的驗證資料，錯一碼就
         * 對不上。
         */
        $number = EcpayScalar::invoiceNumber($inner['InvoiceNo'] ?? null);

        if ($number === null) {
            return EcpayInvoiceResponse::uncertain(InvoiceFailureCode::local('ISSUE', 'NUMBER'));
        }

        $random = EcpayScalar::randomCode($inner['RandomNumber'] ?? null);

        if ($random === null) {
            return EcpayInvoiceResponse::uncertain(InvoiceFailureCode::local('ISSUE', 'RANDOM'));
        }

        $date = $this->text($inner['InvoiceDate'] ?? null);

        // ⛔ 日期必須符合官方格式才算開立成功；解析不出來就不是可信的成功。
        if ($date === null || EcpayInvoiceGateway::parseInvoiceDate($date) === null) {
            return EcpayInvoiceResponse::uncertain(InvoiceFailureCode::local('ISSUE', 'DATE'));
        }

        return EcpayInvoiceResponse::issued($number, $random, $date);
    }

    /**
     * The GetIssue success payload — a different schema entirely.
     *
     * ECPay documents this one with `IIS_*` field names, not the Issue reply's
     * `InvoiceNo`/`InvoiceDate`/`RandomNumber`. Reusing the Issue parser here
     * meant every real query would have come back unreadable, so the one path
     * that exists to resolve an uncertain issue could never have resolved
     * anything.
     *
     * ⛔ Converging requires *positive proof that this invoice, ours, is live*:
     * their merchant id, our RelateNumber, a readable number and date, issued,
     * and not voided. Anything else — a mismatch, a void, a status we do not
     * recognise — stays uncertain. Uncertain costs a human five minutes;
     * guessing wrong costs a duplicate tax document.
     *
     * @param  array<string, mixed>  $inner
     */
    private function readQuery(array $inner, string $merchantId, string $relateNumber): EcpayInvoiceResponse
    {
        // ⭐ 同一個根因：GetIssue 的 `RtnCode` 也可能是字串 `"1"`。
        if (! EcpayScalar::equalsInt($inner['RtnCode'] ?? null, 1)) {
            // ⭐ 查詢自己的數字碼，例如「查無此發票」。
            return EcpayInvoiceResponse::uncertain(
                InvoiceFailureCode::numeric('QUERY', 'RTN', $inner['RtnCode'] ?? null)
            );
        }

        /*
         * ⛔ 必須是我們的商店、我們這次查的那一筆。
         *
         * ⛔ 只記「身份不符」，不記對方回的 MerchantID 或 RelateNumber——
         * 前者是 credential 的一部分，後者可回推訂單編號。
         */
        // ⭐ R2：inner 商店代號套用同一個封閉正規化，並有自己的 code。
        if (! EcpayScalar::merchantMatches($inner['IIS_Mer_ID'] ?? null, $merchantId)) {
            return EcpayInvoiceResponse::uncertain(InvoiceFailureCode::local('QUERY', 'MERCHANT'));
        }

        /*
         * ⛔ RelateNumber 必須是非空字串且與預期值**逐字元全等**——不 trim、
         * 不 cast、不轉大小寫、不做 Unicode 正規化、不移除任何符號。
         *
         * 它是「這張發票屬於哪一張訂單」的唯一鍵；任何正規化都讓「看起來一樣」
         * 的兩個值被視為同一個，那正是把**別張訂單的發票**收斂到這張訂單上的
         * 路徑。
         *
         * ⛔ R3 修正：R2 的註解已經這樣寫，但實作卻呼叫了會先 `trim()` 的
         * `text()`——於是 `" <正確值>"` 與 `"<正確值> "` 都被錯誤接受。註解與
         * 程式不一致，而測試沒有覆蓋「正確值只多空白」這一格，所以綠燈掩蓋了
         * 它。這裡改為專用的 exact-string 判斷，⛔ 刻意不重用 `text()`。
         *
         * ⛔ `text()` 仍供日期等其他欄位使用，其既有行為未改動。
         */
        $relate = $inner['IIS_Relate_Number'] ?? null;

        if (! is_string($relate) || $relate === '' || $relate !== $relateNumber) {
            return EcpayInvoiceResponse::uncertain(InvoiceFailureCode::local('QUERY', 'RELATE'));
        }

        /*
         * ⛔ 已開立且未作廢；狀態不是這兩個確切值就不收斂。
         *
         * ⭐ 官方型別是字串 `"1"`／`"0"`，但同樣可能以整數到達。`statusEquals()`
         * 只放寬 int 與純數字字串，⛔ bool／float／array 仍然拒絕——這一層
         * 決定的是「這張發票現在是不是活的」，不能靠寬鬆轉型猜。
         */
        if (! EcpayScalar::statusEquals($inner['IIS_Issue_Status'] ?? null, '1')) {
            return EcpayInvoiceResponse::uncertain(InvoiceFailureCode::local('QUERY', 'STATUS'));
        }

        if (! EcpayScalar::statusEquals($inner['IIS_Invalid_Status'] ?? null, '0')) {
            return EcpayInvoiceResponse::uncertain(InvoiceFailureCode::local('QUERY', 'STATUS'));
        }

        // ⭐ 與 Issue 完全相同的欄位規則與逐欄代碼。
        $number = EcpayScalar::invoiceNumber($inner['IIS_Number'] ?? null);

        if ($number === null) {
            return EcpayInvoiceResponse::uncertain(InvoiceFailureCode::local('QUERY', 'NUMBER'));
        }

        $random = EcpayScalar::randomCode($inner['IIS_Random_Number'] ?? null);

        if ($random === null) {
            return EcpayInvoiceResponse::uncertain(InvoiceFailureCode::local('QUERY', 'RANDOM'));
        }

        $date = $this->text($inner['IIS_Create_Date'] ?? null);

        if ($date === null || EcpayInvoiceGateway::parseInvoiceDate($date) === null) {
            return EcpayInvoiceResponse::uncertain(InvoiceFailureCode::local('QUERY', 'DATE'));
        }

        return EcpayInvoiceResponse::issued($number, $random, $date);
    }

    /** ⛔ 只接受非空字串；array／bool／float／object 一律 null，不寬鬆 cast。 */
    private function text(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    /** 可覆寫的時間來源，讓 envelope timestamp 在測試中可預期。 */
    protected function now(): CarbonImmutable
    {
        return CarbonImmutable::now();
    }
}
