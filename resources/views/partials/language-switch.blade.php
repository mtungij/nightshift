<div class="language-switch" role="group" aria-label="{{ __('Language') }}">
    @foreach(['en' => 'EN', 'sw' => 'SW'] as $locale => $label)
        <form method="POST" action="{{ route('language.update') }}">
            @csrf
            <input type="hidden" name="locale" value="{{ $locale }}">
            <button type="submit" aria-label="{{ $locale === 'en' ? 'English' : 'Kiswahili' }}" aria-pressed="{{ app()->getLocale() === $locale ? 'true' : 'false' }}">{{ $label }}</button>
        </form>
    @endforeach
</div>
