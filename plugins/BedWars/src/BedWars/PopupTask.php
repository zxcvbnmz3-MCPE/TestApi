<?php
namespace BedWars;

use pocketmine\scheduler\PluginTask;

class PopupTask extends PluginTask{

    public function onRun($currentTick){
        /** @var Main $owner */
        $owner = $this->getOwner();
        $owner->tick();
    }
}
