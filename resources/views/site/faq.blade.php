@php
    $faqs = [
        [__('How do I top up a game?'), __('Choose a game, pick a package, enter your Player ID (and Server ID if the game asks for it), then pay with your wallet balance. Your top-up is sent to your game account within minutes.')],
        [__('How do I add money to my wallet?'), __('Log in, open Wallet and choose Deposit money. Pay with ABA and the amount is added to your wallet once the payment is confirmed.')],
        [__('How long does delivery take?'), __('Most orders are completed within a few minutes. It can take longer when the game server is busy.')],
        [__('Where do I find my Player ID and Server ID?'), __('Open the game and look at your profile or avatar. Your Player ID, and your Server ID or Zone ID if the game has one, are shown there. Enter them exactly as shown.')],
        [__('I entered the wrong ID. Can I get it back?'), __('A top-up goes to the ID you enter and cannot be taken back, so please check it before you pay. For many games the page shows the player name, so confirm it is yours.')],
        [__('What happens if my order fails?'), __('If an order cannot be completed, the amount is refunded to your wallet automatically. You can see it in your Orders and Wallet history.')],
        [__('Is it safe to top up here?'), __('Yes. We never ask for your game password, only your Player ID. Deposits are processed by ABA PayWay.')],
        [__('Do I need an account?'), __('Yes. An account keeps your wallet and order history safe, so you can check your orders at any time.')],
        [__('How do I contact support?'), __('Use the Contact Us page or the Telegram and Facebook buttons at the bottom of the page. We reply as fast as we can.')],
    ];
    $schema = [
        '@context' => 'https://schema.org',
        '@type' => 'FAQPage',
        'mainEntity' => array_map(fn ($f) => [
            '@type' => 'Question',
            'name' => $f[0],
            'acceptedAnswer' => ['@type' => 'Answer', 'text' => $f[1]],
        ], $faqs),
    ];
@endphp
<x-public-layout :title="__('Frequently Asked Questions')" :description="__('Find quick answers about top-ups, deposits and orders.')">
    <style>
        .faq-item { border-bottom: 1px solid rgba(255,255,255,.15); }
        .faq-item summary { cursor: pointer; padding: 1rem 0; font-weight: 600; color: #fff; list-style: none; display: flex; justify-content: space-between; gap: 1rem; }
        .faq-item summary::-webkit-details-marker { display: none; }
        .faq-item summary::after { content: '+'; color: #7dd3fc; font-size: 1.25rem; line-height: 1; }
        .faq-item[open] summary::after { content: '\2212'; }
        .faq-item p { padding-bottom: 1rem; line-height: 1.7; color: #dbeafe; }
    </style>

    <section class="mx-auto max-w-3xl px-4 py-12 sm:px-6 lg:px-8">
        <div class="gg-glass rounded-2xl p-6 sm:p-10">
            <h1 class="text-3xl font-bold text-white sm:text-4xl">{{ __('Frequently Asked Questions') }}</h1>
            <p class="mt-2 text-blue-100">{{ __('Find quick answers about top-ups, deposits and orders.') }}</p>

            <div class="mt-6">
                @foreach ($faqs as [$question, $answer])
                    <details class="faq-item">
                        <summary>{{ $question }}</summary>
                        <p>{{ $answer }}</p>
                    </details>
                @endforeach
            </div>

            <p class="mt-8 text-blue-100">{{ __('Still need help?') }}
                <a href="{{ route('contact') }}" style="color:#7dd3fc;text-decoration:underline">{{ __('Contact Us') }}</a></p>
        </div>
    </section>

    <script type="application/ld+json">{!! json_encode($schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) !!}</script>
</x-public-layout>
