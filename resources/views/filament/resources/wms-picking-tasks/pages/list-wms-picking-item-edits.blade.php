<x-filament-panels::page>
    @php
        $pageStyles = $this->getPageStyles();
    @endphp

    @if($pageStyles)
        {!! $pageStyles !!}
    @endif
</x-filament-panels::page>
