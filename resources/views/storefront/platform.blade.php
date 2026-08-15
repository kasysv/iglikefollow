@extends('layouts.app', [
    'title' => $platform['name'] . ' 服務｜IGLIKEFOLLOW',
    'description' => $platform['name'] . ' 服務總覽：' . $platform['tagline'] . ' 選擇服務類型後即可挑選方案並快速結帳。本頁為本機開發預覽。',
])

@section('content')
<main>
    <nav aria-label="麵包屑" class="mx-auto max-w-[1220px] px-5 pt-8 sm:px-8">
        <ol class="flex flex-wrap items-center gap-2 text-sm text-black/55">
            <li><a href="{{ route('home') }}" class="hover:text-ink hover:underline">首頁</a></li>
            <li aria-hidden="true">/</li>
            <li aria-current="page" class="font-semibold text-ink">{{ $platform['name'] }}</li>
        </ol>
    </nav>

    <section class="mx-auto max-w-[1220px] px-5 py-10 sm:px-8 lg:py-14">
        <p class="eyebrow">{{ $platform['name'] }} hub</p>
        <h1 class="mt-5 max-w-3xl text-[clamp(2.4rem,5vw,4.4rem)] font-bold leading-[1.05] tracking-[-0.05em]">
            {{ $platform['name'] }} 服務
        </h1>
        <p class="mt-5 max-w-2xl text-base leading-8 text-black/60 sm:text-lg">{{ $platform['tagline'] }}</p>
    </section>

    @if ($platform['available'])
        <section class="border-t border-black/10 bg-white">
            <div class="mx-auto max-w-[1220px] px-5 py-14 sm:px-8 lg:py-18">
                <h2 class="text-3xl font-bold tracking-[-0.04em] sm:text-4xl">選擇服務類型</h2>
                <p class="mt-4 max-w-2xl leading-8 text-black/60">
                    每個服務的交付方式與需要填寫的目標不同，請選擇符合需求的類型。
                </p>

                <div class="mt-10 grid gap-5 md:grid-cols-2">
                    @foreach ($platform['services'] as $service)
                        <article class="surface flex flex-col p-6 sm:p-7">
                            <h3 class="text-xl font-bold tracking-[-0.02em] sm:text-2xl">{{ $service['name'] }}</h3>
                            <p class="mt-3 leading-7 text-black/60">{{ $service['summary'] }}</p>
                            <dl class="mt-5 flex-1 space-y-3 text-sm">
                                <div>
                                    <dt class="font-bold">交付方式</dt>
                                    <dd class="mt-1 leading-6 text-black/60">{{ $service['delivery'] }}</dd>
                                </div>
                                <div>
                                    <dt class="font-bold">需要填寫</dt>
                                    <dd class="mt-1 leading-6 text-black/60">{{ $service['input_label'] }}</dd>
                                </div>
                                <div>
                                    <dt class="font-bold">可選款式</dt>
                                    <dd class="mt-1 leading-6 text-black/60">
                                        {{ collect($service['variants'])->pluck('label')->join('、') }}
                                    </dd>
                                </div>
                            </dl>
                            <a href="{{ route('service', [$platform['slug'], $service['slug']]) }}"
                               class="mt-7 inline-flex min-h-14 items-center justify-center rounded-full bg-ink px-6 text-base font-bold text-white transition hover:bg-black">
                                查看方案
                            </a>
                        </article>
                    @endforeach
                </div>
            </div>
        </section>
    @else
        <section class="border-t border-black/10 bg-white">
            <div class="mx-auto max-w-3xl px-5 py-14 sm:px-8 lg:py-20">
                <div class="surface p-7 sm:p-9">
                    <h2 class="text-2xl font-bold tracking-[-0.03em] sm:text-3xl">服務資料準備中</h2>
                    <p class="mt-4 leading-8 text-black/60">{{ $platform['unavailable_note'] }}</p>
                    <p class="mt-4 leading-8 text-black/60">
                        目前沒有可販售的方案、價格或交付時間可以提供。等到服務資料確認後，這一頁才會顯示實際內容。
                    </p>
                    <a href="{{ route('home') }}#platforms"
                       class="mt-7 inline-flex min-h-14 items-center justify-center rounded-full border border-black/15 bg-white px-6 text-base font-bold transition hover:border-ink">
                        查看其他平台服務
                    </a>
                </div>
            </div>
        </section>
    @endif

    <section class="mx-auto max-w-[1220px] px-5 py-14 sm:px-8">
        <h2 class="text-2xl font-bold tracking-[-0.03em]">其他平台</h2>
        <ul class="mt-5 flex flex-wrap gap-3">
            @foreach (config('catalog.platforms') as $other)
                @if ($other['slug'] !== $platform['slug'])
                    <li>
                        <a href="{{ route('platform', $other['slug']) }}"
                           class="inline-flex min-h-12 items-center rounded-full border border-black/15 bg-white px-5 text-sm font-bold transition hover:border-ink">
                            {{ $other['name'] }}
                        </a>
                    </li>
                @endif
            @endforeach
        </ul>
    </section>
</main>
@endsection
