<?php
namespace CTF\game;

use pocketmine\Player;

class InventoryManager{

    private $saved = [];

    public function save(Player $player){
        $name = strtolower($player->getName());
        $inv = $player->getInventory();

        $items = [];
        for($i = 0; $i < $inv->getSize(); $i++){
            $items[$i] = clone $inv->getItem($i);
        }

        $this->saved[$name] = [
            'items' => $items,
            'helmet' => clone $inv->getHelmet(),
            'chestplate' => clone $inv->getChestplate(),
            'leggings' => clone $inv->getLeggings(),
            'boots' => clone $inv->getBoots()
        ];
    }

    public function restore(Player $player){
        $name = strtolower($player->getName());
        if(!isset($this->saved[$name])){
            return;
        }

        $inv = $player->getInventory();
        $inv->clearAll();
        foreach($this->saved[$name]['items'] as $slot => $item){
            $inv->setItem($slot, clone $item);
        }

        $inv->setHelmet(clone $this->saved[$name]['helmet']);
        $inv->setChestplate(clone $this->saved[$name]['chestplate']);
        $inv->setLeggings(clone $this->saved[$name]['leggings']);
        $inv->setBoots(clone $this->saved[$name]['boots']);

        unset($this->saved[$name]);
    }
}
