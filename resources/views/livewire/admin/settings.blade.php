<?php

use Livewire\Volt\Component;
use function Livewire\Volt\{layout, title};

layout('components.layouts.public');
title('Systeminställningar');

new class extends Component {}; ?>

<div class="mx-auto max-w-3xl px-4 py-8 sm:px-6">

    @php $tab = request()->query('tab', 'allmant'); @endphp

    <div @class(['hidden' => $tab !== 'allmant'])>
        @livewire('admin.settings.general')
    </div>

    <div @class(['hidden' => $tab !== 'moduler'])>
        @livewire('admin.settings.modules')
    </div>

    <div @class(['hidden' => $tab !== 'bokningar'])>
        @livewire('admin.settings.booking')
    </div>

    <div @class(['hidden' => $tab !== 'medlemskap'])>
        @livewire('admin.settings.membership')
    </div>

    <div @class(['hidden' => $tab !== 'betalning'])>
        @livewire('admin.settings.payment')
    </div>

    <div @class(['hidden' => $tab !== 'discord'])>
        @livewire('admin.settings.discord')
    </div>

    <div @class(['hidden' => $tab !== 'grupper'])>
        @livewire('admin.settings.sections')
    </div>

    <div @class(['hidden' => $tab !== 'serverinfo'])>
        @livewire('admin.settings.server-info')
    </div>

</div>
