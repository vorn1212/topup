<x-public-layout :title="__('Contact Us')" :description="__('Contact us on Telegram or Facebook. We reply as fast as we can.')">
    @inject('site', 'App\Services\SiteSettings')

    <section class="mx-auto max-w-3xl px-4 py-14 sm:px-6 lg:px-8">
        <div class="gg-glass rounded-2xl p-8 text-center sm:p-12">
            <h1 class="text-3xl font-bold text-white sm:text-4xl">{{ __('Contact Us') }}</h1>
            <p class="mt-3 text-blue-100">{{ __('Need help with a top-up or an order? Message us and we will reply as fast as we can.') }}</p>

            <div class="mt-8">
                @if ($site->hasContact())
                    @include('site._contact-buttons', ['big' => true])
                @else
                    <p class="text-blue-200">{{ __('Contact details are coming soon.') }}</p>
                @endif
            </div>
        </div>
    </section>
</x-public-layout>
