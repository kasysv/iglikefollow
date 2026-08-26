<?php

namespace App\Policies;

use App\Models\FulfillmentOrder;
use App\Models\User;

/**
 * Fulfilment records are read-only. Existing rows are never edited.
 *
 * Support staff need to see where an order stands, so editors can read.
 *
 * ⛔ There is no retry, no cancel and no "mark as completed", by design rather
 * than by omission. Each of those is a claim about what a supplier did, and a
 * person clicking a button in our admin cannot make it true — a row marked
 * completed by hand would tell the next person the customer was served when
 * nobody knows that. Rows that need judgement stop in `submission_unknown` and
 * wait for someone to check with the provider directly.
 *
 * ⭐ `replace()` 是這條規則的唯一例外——而且它**並不違反**那條規則：它不改寫
 * 任何既有列，而是建立**新的一批**。舊批次的 status、provider 原文與 Remains
 * 完全不動，仍由既有排程繼續同步。
 *
 * ⛔ 為什麼它是安全的：Owner 建立更換履約時，宣稱的不是「供應商做了什麼」，
 * 而是「我自己在 SMM PANEL 處理過舊單，現在要送一批新的」——那是一個他確實
 * 知道、而且只有他知道的事實。上面那條禁令針對的是**替供應商代言**，
 * 這一項不是。
 */
class FulfillmentOrderPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isOwner() || $user->isEditor();
    }

    public function view(User $user, FulfillmentOrder $fulfillment): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return false;
    }

    /**
     * May this user create a replacement batch for this row?
     *
     * ⛔ Owner-only。Editor 即使直接呼叫也必須被拒絕——`viewAny()` 讓他讀得到
     * 這些列，但讀與建立是兩件事。
     *
     * ⛔ 這裡只回答「這個人可不可以」。「這一批能不能被更換」（有無
     * provider order ID、是否已有 child、訂單是否已付款、派單開關是否開啟）
     * 全部由 `CreateFulfillmentReplacement` 在 transaction 與 row lock 內
     * 重新驗證——⛔ policy 讀到的是可能已經過期的快照，不足以當防線。
     */
    public function replace(User $user, FulfillmentOrder $fulfillment): bool
    {
        return $user->isOwner();
    }

    public function update(User $user, FulfillmentOrder $fulfillment): bool
    {
        return false;
    }

    public function delete(User $user, FulfillmentOrder $fulfillment): bool
    {
        return false;
    }

    public function restore(User $user, FulfillmentOrder $fulfillment): bool
    {
        return false;
    }

    public function forceDelete(User $user, FulfillmentOrder $fulfillment): bool
    {
        return false;
    }
}
