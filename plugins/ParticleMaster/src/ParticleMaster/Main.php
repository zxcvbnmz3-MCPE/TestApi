<?php
namespace ParticleMaster;

use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerQuitEvent;
use pocketmine\level\particle\CriticalParticle;
use pocketmine\level\particle\DustParticle;
use pocketmine\level\particle\EnchantParticle;
use pocketmine\level\particle\FlameParticle;
use pocketmine\level\particle\HappyVillagerParticle;
use pocketmine\level\particle\HeartParticle;
use pocketmine\level\particle\PortalParticle;
use pocketmine\level\particle\RedstoneParticle;
use pocketmine\level\particle\SmokeParticle;
use pocketmine\level\particle\SpellParticle;
use pocketmine\math\Vector3;
use pocketmine\plugin\PluginBase;
use pocketmine\Player;

class Main extends PluginBase implements Listener{

    /** @var array */
    private $activeEffects = [];

    /** @var array */
    private $presets = [];

    /** @var int */
    private $tick = 0;

    /** @var int */
    private $maxParticlesPerTick = 24;

    public function onEnable(){
        @mkdir($this->getDataFolder());
        $this->saveDefaultConfig();

        $this->presets = $this->buildPresets();
        $this->maxParticlesPerTick = (int) $this->getConfig()->getNested("settings.max-particles-per-tick", 24);

        $this->getServer()->getPluginManager()->registerEvents($this, $this);
        $interval = (int) $this->getConfig()->getNested("settings.tick-interval", 2);
        $this->getServer()->getScheduler()->scheduleRepeatingTask(new ParticleTask($this), max(1, $interval));

        $this->getLogger()->info("ParticleMaster loaded with " . count($this->presets) . " particle styles.");
    }

    public function onQuit(PlayerQuitEvent $event){
        unset($this->activeEffects[strtolower($event->getPlayer()->getName())]);
    }

    public function onCommand(CommandSender $sender, Command $command, $label, array $args){
        if(strtolower($command->getName()) !== "particlefx"){
            return false;
        }

        if(count($args) === 0){
            if($sender instanceof Player){
                $default = (string) $this->getConfig()->getNested("settings.default-effect", "ring_ruby");
                if(isset($this->presets[$default])){
                    $this->activeEffects[strtolower($sender->getName())] = $default;
                    $sender->sendMessage($this->prefix() . "تم تفعيل التأثير الافتراضي: §e" . $default);
                    return true;
                }
            }
            $this->sendUsage($sender);
            return true;
        }

        $sub = strtolower($args[0]);

        if($sub === "list"){
            $page = isset($args[1]) ? max(1, (int) $args[1]) : 1;
            $this->sendList($sender, $page);
            return true;
        }

        if($sub === "set"){
            if(!($sender instanceof Player)){
                $sender->sendMessage($this->prefix() . "هذا الأمر للاعبين فقط.");
                return true;
            }
            if(!isset($args[1])){
                $sender->sendMessage($this->prefix() . "استخدم: /particlefx set <name>");
                return true;
            }
            $this->setEffect($sender, strtolower($args[1]));
            return true;
        }

        if($sub === "off"){
            if($sender instanceof Player){
                unset($this->activeEffects[strtolower($sender->getName())]);
                $sender->sendMessage($this->prefix() . "تم إيقاف التأثيرات.");
            }
            return true;
        }

        if($sub === "random"){
            if(!($sender instanceof Player)){
                return true;
            }
            if(!$sender->hasPermission("particlemaster.random")){
                $sender->sendMessage($this->prefix() . "لا تملك الصلاحية particlemaster.random");
                return true;
            }
            $keys = array_keys($this->presets);
            $name = $keys[array_rand($keys)];
            $this->setEffect($sender, $name);
            return true;
        }

        if($sub === "info"){
            if(!isset($args[1])){
                $sender->sendMessage($this->prefix() . "استخدم: /particlefx info <name>");
                return true;
            }
            $name = strtolower($args[1]);
            if(!isset($this->presets[$name])){
                $sender->sendMessage($this->prefix() . "اسم تأثير غير موجود.");
                return true;
            }
            $p = $this->presets[$name];
            $sender->sendMessage($this->prefix() . "§e" . $name . " §7| الشكل: §b" . $p["pattern"] . " §7| النمط: §d" . $p["emitter"] . " §7| اللون: §cRGB(" . $p["r"] . "," . $p["g"] . "," . $p["b"] . ")");
            $sender->sendMessage("§7Permission: §a" . $p["permission"]);
            return true;
        }

        if($sub === "me"){
            if(!($sender instanceof Player)){
                return true;
            }
            $n = strtolower($sender->getName());
            if(isset($this->activeEffects[$n])){
                $sender->sendMessage($this->prefix() . "تأثيرك الحالي: §e" . $this->activeEffects[$n]);
            }else{
                $sender->sendMessage($this->prefix() . "لا يوجد تأثير مفعل.");
            }
            return true;
        }

        if($sub === "reload"){
            if(!$sender->hasPermission("particlemaster.reload")){
                $sender->sendMessage($this->prefix() . "لا تملك الصلاحية particlemaster.reload");
                return true;
            }
            $this->reloadConfig();
            $this->maxParticlesPerTick = (int) $this->getConfig()->getNested("settings.max-particles-per-tick", 24);
            $sender->sendMessage($this->prefix() . "تم إعادة تحميل الإعدادات.");
            return true;
        }

        if($sender instanceof Player && isset($this->presets[$sub])){
            $this->setEffect($sender, $sub);
            return true;
        }

        $this->sendUsage($sender);
        return true;
    }

    private function sendUsage(CommandSender $sender){
        $sender->sendMessage($this->prefix() . "§e/particlefx list [page]");
        $sender->sendMessage($this->prefix() . "§e/particlefx set <name>");
        $sender->sendMessage($this->prefix() . "§e/particlefx <name> §7(اختصار مباشر)");
        $sender->sendMessage($this->prefix() . "§e/particlefx random | off | me | info <name>");
        $sender->sendMessage($this->prefix() . "المتوفر حالياً: §a" . count($this->presets) . "§7 تأثير مختلف.");
    }

    private function sendList(CommandSender $sender, $page){
        $perPage = 12;
        $names = array_keys($this->presets);
        $totalPages = max(1, (int) ceil(count($names) / $perPage));
        if($page > $totalPages){
            $page = $totalPages;
        }
        $start = ($page - 1) * $perPage;
        $slice = array_slice($names, $start, $perPage);
        $sender->sendMessage($this->prefix() . "§7الصفحة §e" . $page . "§7/§e" . $totalPages . " §7(المجموع " . count($names) . ")");
        foreach($slice as $name){
            $p = $this->presets[$name];
            $sender->sendMessage("§8- §b" . $name . " §7| §d" . $p["pattern"] . " §7| RGB(" . $p["r"] . "," . $p["g"] . "," . $p["b"] . ")");
        }
    }

    private function setEffect(Player $player, $name){
        if(!isset($this->presets[$name])){
            $player->sendMessage($this->prefix() . "اسم تأثير غير معروف.");
            return;
        }

        $permission = $this->presets[$name]["permission"];
        if(!$player->hasPermission($permission) && !$player->hasPermission("particlemaster.effect.*")){
            $player->sendMessage($this->prefix() . "لا تملك الصلاحية §c" . $permission);
            return;
        }

        $this->activeEffects[strtolower($player->getName())] = $name;
        $p = $this->presets[$name];
        $player->sendMessage($this->prefix() . "تم تفعيل: §e" . $name . " §7| اللون RGB(" . $p["r"] . "," . $p["g"] . "," . $p["b"] . ")");
    }

    public function tickParticles(){
        $this->tick++;
        foreach($this->activeEffects as $playerName => $effectName){
            $player = $this->getServer()->getPlayerExact($playerName);
            if(!($player instanceof Player) || !$player->isOnline() || $player->isClosed()){
                unset($this->activeEffects[$playerName]);
                continue;
            }
            if(!isset($this->presets[$effectName])){
                unset($this->activeEffects[$playerName]);
                continue;
            }
            $this->renderEffect($player, $this->presets[$effectName]);
        }
    }

    private function renderEffect(Player $player, array $preset){
        $center = new Vector3($player->x, $player->y + 1.1, $player->z);
        $pattern = $preset["pattern"];
        $radius = $preset["radius"];
        $speed = $preset["speed"];
        $points = [];

        if($pattern === "ring"){
            for($i = 0; $i < 12; $i++){
                $a = (($this->tick * $speed) + $i * 30) * M_PI / 180;
                $points[] = new Vector3($center->x + cos($a) * $radius, $center->y + 0.2, $center->z + sin($a) * $radius);
            }
        }elseif($pattern === "double_ring"){
            for($i = 0; $i < 8; $i++){
                $a = (($this->tick * $speed) + $i * 45) * M_PI / 180;
                $points[] = new Vector3($center->x + cos($a) * $radius, $center->y + 0.15, $center->z + sin($a) * $radius);
                $points[] = new Vector3($center->x + cos(-$a) * ($radius * 0.6), $center->y + 0.65, $center->z + sin(-$a) * ($radius * 0.6));
            }
        }elseif($pattern === "helix"){
            for($i = 0; $i < 12; $i++){
                $a = (($this->tick * $speed * 1.7) + $i * 30) * M_PI / 180;
                $y = $center->y + ($i % 6) * 0.18;
                $points[] = new Vector3($center->x + cos($a) * ($radius * 0.65), $y, $center->z + sin($a) * ($radius * 0.65));
            }
        }elseif($pattern === "orbit"){
            $a = ($this->tick * ($speed * 2.3)) * M_PI / 180;
            $points[] = new Vector3($center->x + cos($a) * $radius, $center->y + 0.35, $center->z + sin($a) * $radius);
            $points[] = new Vector3($center->x + cos($a + M_PI) * $radius, $center->y + 0.35, $center->z + sin($a + M_PI) * $radius);
            $points[] = new Vector3($center->x, $center->y + 0.75 + sin($a * 2.0) * 0.12, $center->z);
        }elseif($pattern === "burst"){
            for($i = 0; $i < 14; $i++){
                $x = (mt_rand(-100, 100) / 100) * $radius;
                $y = (mt_rand(0, 100) / 100) * 1.4;
                $z = (mt_rand(-100, 100) / 100) * $radius;
                $points[] = new Vector3($center->x + $x, $center->y + $y, $center->z + $z);
            }
        }elseif($pattern === "wings"){
            for($i = -4; $i <= 4; $i++){
                $arc = $i / 4;
                $points[] = new Vector3($center->x + 0.7 + ($arc * 0.35), $center->y + 0.25 + abs($arc) * 0.35, $center->z + $arc * 0.55);
                $points[] = new Vector3($center->x - 0.7 - ($arc * 0.35), $center->y + 0.25 + abs($arc) * 0.35, $center->z + $arc * 0.55);
            }
        }elseif($pattern === "crown"){
            for($i = 0; $i < 8; $i++){
                $a = ($i * 45 + $this->tick * $speed) * M_PI / 180;
                $x = $center->x + cos($a) * ($radius * 0.75);
                $z = $center->z + sin($a) * ($radius * 0.75);
                $points[] = new Vector3($x, $center->y + 0.95, $z);
                if($i % 2 === 0){
                    $points[] = new Vector3($x, $center->y + 1.2, $z);
                }
            }
        }elseif($pattern === "rain"){
            for($i = 0; $i < 12; $i++){
                $x = (mt_rand(-100, 100) / 100) * $radius;
                $z = (mt_rand(-100, 100) / 100) * $radius;
                $y = 1.8 - (($this->tick + $i) % 8) * 0.2;
                $points[] = new Vector3($center->x + $x, $center->y + $y, $center->z + $z);
            }
        }elseif($pattern === "trail"){
            $dir = $player->getDirectionVector();
            for($i = 1; $i <= 10; $i++){
                $d = $i * 0.18;
                $points[] = new Vector3($center->x - $dir->x * $d, $center->y + 0.05, $center->z - $dir->z * $d);
            }
        }elseif($pattern === "totem"){
            for($i = 0; $i < 10; $i++){
                $a = (($this->tick * $speed * 1.6) + $i * 36) * M_PI / 180;
                $points[] = new Vector3($center->x + cos($a) * ($radius * 0.55), $center->y + ($i % 5) * 0.2, $center->z + sin($a) * ($radius * 0.55));
            }
        }elseif($pattern === "star"){
            for($i = 0; $i < 5; $i++){
                $a = ($this->tick * $speed + $i * 72) * M_PI / 180;
                $b = $a + 36 * M_PI / 180;
                $points[] = new Vector3($center->x + cos($a) * $radius, $center->y + 0.6, $center->z + sin($a) * $radius);
                $points[] = new Vector3($center->x + cos($b) * ($radius * 0.45), $center->y + 0.6, $center->z + sin($b) * ($radius * 0.45));
            }
        }elseif($pattern === "cube"){
            $s = $radius * 0.65;
            $corners = [
                [$s, 0, $s], [$s, 0, -$s], [-$s, 0, $s], [-$s, 0, -$s],
                [$s, 1.0, $s], [$s, 1.0, -$s], [-$s, 1.0, $s], [-$s, 1.0, -$s]
            ];
            foreach($corners as $c){
                $points[] = new Vector3($center->x + $c[0], $center->y + $c[1], $center->z + $c[2]);
            }
        }

        $count = 0;
        foreach($points as $p){
            $player->getLevel()->addParticle($this->makeParticle($p, $preset));
            $count++;
            if($count >= $this->maxParticlesPerTick){
                break;
            }
        }
    }

    private function makeParticle(Vector3 $pos, array $preset){
        $emitter = $preset["emitter"];
        $r = $preset["r"];
        $g = $preset["g"];
        $b = $preset["b"];

        if($emitter === "dust") return new DustParticle($pos, $r, $g, $b, 220);
        if($emitter === "spell") return new SpellParticle($pos, $r, $g, $b, 220);
        if($emitter === "flame") return new FlameParticle($pos);
        if($emitter === "portal") return new PortalParticle($pos);
        if($emitter === "heart") return new HeartParticle($pos, 1);
        if($emitter === "smoke") return new SmokeParticle($pos, 4);
        if($emitter === "redstone") return new RedstoneParticle($pos, 2);
        if($emitter === "happy") return new HappyVillagerParticle($pos);
        if($emitter === "critical") return new CriticalParticle($pos, 2);
        if($emitter === "enchant") return new EnchantParticle($pos);

        return new DustParticle($pos, $r, $g, $b, 220);
    }

    private function buildPresets(){
        $patterns = ["ring", "double_ring", "helix", "orbit", "burst", "wings", "crown", "rain", "trail", "totem", "star", "cube"];
        $emitters = ["dust", "spell", "flame", "portal", "heart", "smoke", "redstone", "happy", "critical", "enchant"];

        $palettes = [
            ["name" => "ruby", "r" => 255, "g" => 56, "b" => 110],
            ["name" => "sapphire", "r" => 71, "g" => 145, "b" => 255],
            ["name" => "emerald", "r" => 43, "g" => 212, "b" => 126],
            ["name" => "amber", "r" => 255, "g" => 180, "b" => 60],
            ["name" => "violet", "r" => 172, "g" => 111, "b" => 255],
            ["name" => "rose", "r" => 255, "g" => 104, "b" => 178],
            ["name" => "ice", "r" => 140, "g" => 235, "b" => 255],
            ["name" => "lime", "r" => 162, "g" => 255, "b" => 78],
            ["name" => "sun", "r" => 255, "g" => 246, "b" => 95],
            ["name" => "night", "r" => 75, "g" => 90, "b" => 170],
            ["name" => "void", "r" => 110, "g" => 96, "b" => 145],
            ["name" => "aqua", "r" => 63, "g" => 247, "b" => 221]
        ];

        $presets = [];
        $i = 0;
        foreach($patterns as $pi => $pattern){
            foreach($palettes as $ci => $color){
                $name = $pattern . "_" . $color["name"];
                $emitter = $emitters[($pi + $ci + $i) % count($emitters)];
                $presets[$name] = [
                    "name" => $name,
                    "pattern" => $pattern,
                    "emitter" => $emitter,
                    "r" => $color["r"],
                    "g" => $color["g"],
                    "b" => $color["b"],
                    "radius" => 0.65 + (($ci % 5) * 0.09),
                    "speed" => 3 + (($pi + $ci) % 7),
                    "permission" => "particlemaster.effect." . $name
                ];
                $i++;
            }
        }

        return $presets;
    }

    private function prefix(){
        return (string) $this->getConfig()->getNested("messages.prefix", "§dParticleMaster §8» §r");
    }
}
