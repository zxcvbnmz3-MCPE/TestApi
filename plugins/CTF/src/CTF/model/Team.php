<?php
namespace CTF\model;

use pocketmine\level\Position;

class Team{

    /** @var string */
    private $id;
    /** @var string */
    private $display;
    /** @var string */
    private $color;
    /** @var Position */
    private $spawn;

    public function __construct($id, $display, $color, Position $spawn){
        $this->id = $id;
        $this->display = $display;
        $this->color = $color;
        $this->spawn = $spawn;
    }

    public function getId(){ return $this->id; }
    public function getDisplay(){ return $this->display; }
    public function getColor(){ return $this->color; }
    public function getSpawn(){ return $this->spawn; }

    public function getColoredName(){
        return $this->color . $this->display;
    }
}
