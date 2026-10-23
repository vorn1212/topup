<x-public-layout :title="__('Privacy Policy')" :description="__('How we collect, use and protect your information when you top up games with us.')">
    <style>
        .legal-card h2 { margin-top: 1.75rem; font-size: 1.15rem; font-weight: 700; color: #fff; }
        .legal-card p, .legal-card li { margin-top: .5rem; line-height: 1.7; color: #dbeafe; }
        .legal-card ul { margin-top: .5rem; padding-left: 1.25rem; list-style: disc; }
        .legal-card a { color: #7dd3fc; text-decoration: underline; }
    </style>

    <section class="mx-auto max-w-3xl px-4 py-12 sm:px-6 lg:px-8">
        <div class="gg-glass legal-card rounded-2xl p-6 sm:p-10">
            <h1 class="text-3xl font-bold text-white sm:text-4xl">{{ __('Privacy Policy') }}</h1>
            <p>{{ __('Last updated') }}: 2026-10-23</p>
            <p>{{ __('This page explains what information we collect when you use our game top-up shop, why we collect it, and the choices you have.') }}</p>

            <h2>{{ __('Information we collect') }}</h2>
            <ul>
                <li>{{ __('Account details: your name, email address and, if you give it, your phone number. If you sign in with Google, we receive your name and email address from Google.') }}</li>
                <li>{{ __('Order details: the game, package, Player ID and Server ID you enter, and your deposit, wallet and order history.') }}</li>
                <li>{{ __('Technical data: basic information such as your IP address and browser type, which we use to keep the site secure and working.') }}</li>
            </ul>

            <h2>{{ __('How we use your information') }}</h2>
            <ul>
                <li>{{ __('To create your account and keep you signed in.') }}</li>
                <li>{{ __('To process your deposits and deliver your top-ups.') }}</li>
                <li>{{ __('To send you messages about your orders and account, such as order updates and password resets.') }}</li>
                <li>{{ __('To prevent fraud and abuse, and to answer your support requests.') }}</li>
            </ul>

            <h2>{{ __('Who we share it with') }}</h2>
            <p>{{ __('We do not sell your personal information. We share only what is needed to provide the service:') }}</p>
            <ul>
                <li>{{ __('Our game top-up supplier receives the game, package, Player ID and Server ID so that your top-up can be delivered.') }}</li>
                <li>{{ __('Our payment provider, ABA PayWay, processes your deposits. We do not see or store your bank login or card details.') }}</li>
                <li>{{ __('Our hosting and security providers, such as Cloudflare, and our email provider handle data on our behalf to run the site and send messages.') }}</li>
                <li>{{ __('We may disclose information when the law requires it.') }}</li>
            </ul>

            <h2>{{ __('Cookies') }}</h2>
            <p>{{ __('We use only the cookies the site needs to work, for example to keep you signed in and to remember your language. We do not use cookies to sell advertising.') }}</p>

            <h2>{{ __('Security and storage') }}</h2>
            <p>{{ __('Passwords are stored in a protected form and our backups are encrypted. We keep your information while your account is active and as long as we need it for order records, accounting or to resolve disputes.') }}</p>

            <h2>{{ __('Your choices') }}</h2>
            <p>{{ __('You can update your details in your profile at any time. To ask us to correct or delete your information, contact us and we will help.') }}
                <a href="{{ route('contact') }}">{{ __('Contact Us') }}</a></p>

            <h2>{{ __('Changes to this policy') }}</h2>
            <p>{{ __('We may update this policy from time to time. The date at the top shows when it was last changed.') }}</p>
        </div>
    </section>
</x-public-layout>
