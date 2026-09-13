@props(['guide'])

<section data-probe="variant-guide" aria-labelledby="variant-guide-title"
         class="border-t border-black/10 bg-white">
    <div class="mx-auto max-w-[1320px] px-5 py-10 sm:px-8">
        <h2 id="variant-guide-title" class="text-2xl font-bold tracking-[-0.03em]">{{ $guide['heading'] }}</h2>
        <dl class="mt-6 grid gap-4 sm:grid-cols-2">
            @foreach (\App\Support\VariantGuide::LABELS as $key => $label)
                <div class="rounded-2xl border border-black/10 p-5">
                    <dt class="text-lg font-bold">{{ $label }}</dt>
                    <dd class="mt-2 whitespace-pre-line break-words text-base leading-7 text-black/80">{{ $guide[$key] }}</dd>
                </div>
            @endforeach
        </dl>
        <div class="mt-6 rounded-2xl bg-paper p-5">
            <h3 class="text-lg font-bold">{{ $guide['choice_heading'] }}</h3>
            <ul class="mt-3 space-y-2 text-base leading-7">
                @foreach (['real', 'premium', 'standard'] as $key)
                    <li class="break-words">{{ $guide['choice_'.$key] }} → 選 <strong>{{ \App\Support\VariantGuide::LABELS[$key] }}</strong></li>
                @endforeach
            </ul>
        </div>
    </div>
</section>
