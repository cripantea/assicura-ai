<x-filament-panels::page>

    {{ $this->form  }}

    <div class="mt-4 flex gap-2">
        <x-filament::button wire:click="generate" icon="heroicon-o-sparkles">
            Generate email
        </x-filament::button>


    </div>


</x-filament-panels::page>

