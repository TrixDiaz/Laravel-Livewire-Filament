<?php

namespace App\Livewire;

use Livewire\Component;

class Motherboard extends Component
{
    public $modelPath;

    public function mount()
    {
        $this->modelPath = asset('models/window.glb');
    }

    public function render()
    {
        return view('livewire.motherboard');
    }
}