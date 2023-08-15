<?php

declare(strict_types=1);


namespace BedWars\game;

use BedWars\BedWars;
use BedWars\game\Team;
use BedWars\utils\Utils;

use pocketmine\plugin\Plugin;
use pocketmine\scheduler\Task;
use pocketmine\utils\TextFormat;
use pocketmine\math\Vector3;

use pocketmine\nbt\tag\{
    CompoundTag, IntTag, ListTag, DoubleTag, FloatTag, StringTag
};
use pocketmine\entity\Entity;
use pocketmine\event\entity\{
    EntityDamageEvent, EntityDamageByChildEntityEvent
};
use pocketmine\event\player\{
    PlayerDeathEvent, PlayerRespawnEvent
};
use pocketmine\event\Listener;
use pocketmine\Player;

use pocketmine\item\enchantment\Enchantment;
use pocketmine\item\enchantment\EnchantmentInstance;

use pocketmine\item\Item;
use pocketmine\level\{
    Level, Position
};

use pocketmine\item\Compass;
use pocketmine\utils\Config;

//PARTICLES

use pocketmine\level\particle\{ DustParticle, SmokeParticle, RainSplashParticle, HeartParticle, FlameParticle, RedstoneParticle, LavaParticle, LavaDripParticle, WaterParticle, PortalParticle, HappyVillagerParticle
};

//scoreboard
use Miste\scoreboardspe\API\{
    Scoreboard, ScoreboardDisplaySlot, ScoreboardSort
};


use pocketmine\network\mcpe\protocol\{
TextPacket, LevelSoundEventPacket, LevelSoundEvent
};

use onebone\economyapi\EconomyAPI;


class Game
{

    const STATE_LOBBY = 0;
    const STATE_RUNNING = 1;
    const STATE_REBOOT = 2;


    /** @var BedWars $plugin */
    private $plugin;

    /** @var string $gameName */
    private $gameName;

    /** @var int $minPlayers */
    private $minPlayers;

    /** @var int $maxPlayers */
    private $maxPlayers;

    /** @var int $playersPerTeam */
    public $playersPerTeam;

    /** @var string $worldName */
    public $worldName;

    /** @var string $lobbyName */
    private $lobbyName;

    /** @var int $state */
    private $state = self::STATE_LOBBY;

    /** @var array $players */
    public $players = array();

    /** @var array $spectators */
    public $spectators = array();

    /** @var bool $starting */
    private $starting = false;

    /** @var Vector3 $lobby */
    private $lobby;

    /** @var int $startTime */
    private $startTime = 30;

    /** @var int $rebootTime */
    private $rebootTime = 10;

    /** @var int $voidY */
    private $voidY ;

    /** @var array $teamInfo */
    public $teamInfo = array();

    /** @var array $teams */
    public $teams = array();

    /** @var array $deadQueue */
    public $deadQueue = [];

    /** @var string $winnerTeam */
    private $winnerTeam = '';

    /** @var Entity[] $npcs */
    public $npcs = [];

    /** @var array $trackingPositions */
    private $trackingPositions = [];

    /** @var Generator[] $generators */
    public $generators = array();

    /** @var array $generatorInfo */
    private $generatorInfo = array();

    /** @var float|int $tierUpdate */
    private $tierUpdate = 160 * 10;

    /** @var string $tierUpdateGen */
    private $tierUpdateGen = "diamond";

    /** @var array $dynamicStats */
    private $dynamicStats = array();

    /** @var array $placedBlocks */
    public $placedBlocks = array();

    /** @var bool $needLoad */
    private $needLoad = false;


    /**
     * Game constructor.
     * @param BedWars $plugin
     * @param string $arenaName
     * @param int $minPlayers
     * @param int $playersPerTeam
     * @param string $worldName
     * @param string $lobbyWorld
     * @param array $teamInfo
     * @param array $generatorInfo
     */
    public function __construct(BedWars $plugin, string $arenaName, int $minPlayers, int $playersPerTeam, string $worldName, string $lobbyWorld, array $teamInfo, array $generatorInfo)
    {
        $this->plugin = $plugin;
        $this->gameName = $arenaName;
        $this->minPlayers = $minPlayers;
        $this->playersPerTeam = $playersPerTeam;
        $this->worldName = $worldName;
        $this->lobbyName = explode(":", $lobbyWorld)[3];
        $this->teamInfo = $teamInfo;
        $this->generatorInfo = !isset($generatorInfo[$this->gameName]) ? [] : $generatorInfo[$this->gameName];

        foreach($this->teamInfo as $teamName => $data){
             $this->teams[$teamName] = new Team($teamName, BedWars::TEAMS[strtolower($teamName)]);
        }

        $this->maxPlayers = count($this->teams) * $playersPerTeam;


        $this->reload();

        $this->plugin->getScheduler()->scheduleRepeatingTask(new class($this) extends Task{

            private $plugin;

            /**
             *  constructor.
             * @param Game $plugin
             */
            public function __construct(Game $plugin)
            {
                $this->plugin = $plugin;
            }

            public function onRun(int $currentTick)
            {
                $this->plugin->tick();
            }
        }, 20);

    }

    /**
     * @param int $limit
     */
    public function setVoidLimit(int $limit) : void{
        $this->voidY = $limit;
    }

    /**
     * @param Vector3 $lobby
     * @param string $worldName
     */
    public function setLobby(Vector3 $lobby, string $worldName) : void{
        $this->lobby = new Position($lobby->x, $lobby->y, $lobby->z, $this->plugin->getServer()->getLevelByName($worldName));
    }

    /**
     * @return int
     */
    public function getVoidLimit() : int{
        return $this->voidY;
    }

    /**
     * @return int
     */
    public function getState() : int{
        return $this->state;
    }

    /**
     * @return string
     */
    public function getName() : string{
        return $this->gameName;
    }

    /**
     * @return int
     */
    public function getMaxPlayers() : int{
        return $this->maxPlayers;
    }

    public function reload() : void{
        $this->plugin->getServer()->loadLevel($this->worldName);
        $world = $this->plugin->getServer()->getLevelByName($this->worldName);
        if(!$world instanceof Level){
            $this->plugin->getLogger()->info(BedWars::PREFIX . TextFormat::YELLOW . "§r§cFailed to load arena§6 " . $this->gameName . "§c because it's world does not exist!");
            return;
        }
        $world->setAutoSave(false);

    }

    /**
     * @param string $message
     */
    public function broadcastMessage(string $message) : void{
        foreach(array_merge($this->spectators, $this->players) as $player){
            $player->sendMessage(BedWars::PREFIX . $message);
        }
    }

    /**
     * @return array
     */
    public function getAliveTeams() : array{
      /*  $teams = [];

       /* for($i = 1; $i < (count($this->teams)); $i++){
            $players = [];
            $team = array_values($this->teams)[$i];
            foreach($team->getPlayers() as $p){
                if(!$p->isOnline() || $p->level->getFolderName() !== $this->worldName){
                    $this->quit($p);
                    continue;
                }

                if($p->isAlive() && $team->hasBed()){
                    $players[] = $p;
                }
            }

            if(count($players) >= 1){
                $teams[] = $team->getName();
            }

        }*/
        $teams = [];
        foreach($this->teams as $team){
            if(count($team->getPlayers()) <= 0 || !$team->hasBed())continue;
            $players = [];

            foreach($team->getPlayers() as $player){
                
                    $players[] = $player;
                }
            if(count($players) >= 1){
                $teams[] = $team->getName();
               }

           }
        return $teams;
    }

    public function stop() : void{
        foreach(array_merge($this->players, $this->spectators) as $player){
            //KILLS
            $config = new Config("plugin_data/BedWars/kills.yml", Config::YAML);
            $config->getAll();
        $config->set($player->getName(), $config->remove($player->getName(), "               "));
            $config->set($player->getName(), $config->remove($player->getName(), "0"));
            $config->save();
            //FINAL KILLS
            $xconfig = new Config("plugin_data/BedWars/Fkills.yml", Config::YAML);
            $xconfig->getAll();
            $xconfig->set($player->getName(), $xconfig->remove($player->getName(), "               "));
           $xconfig->set($player->getName(), $xconfig->remove($player->getName(), "0"));
           $xconfig->save();
           //BEDS
           $bkconfig = new Config("plugin_data/BedWars/bed.yml", Config::YAML);
           $bkconfig->set($player->getName(), $bkconfig->remove($player->getName(), "               "));
           $bkconfig->set($player->getName(), $bkconfig->remove($player->getName(), "0"));
           $bkconfig->getAll();
        
             $player->setHealth($player->getMaxHealth());                         $player->getInventory()->clearAll();
            $player->getArmorInventory()->clearAll();
            $player->getCursorInventory()->clearAll();
            $player->setFood(20);
            $player->setHealth(20);
            $player->setGamemode(0);
            $player->setNameTag($player->getName()); 
        //TODO: save this before starting the game
            \BedWars\utils\Scoreboard::remove($player);
        }

        foreach($this->teams as $team){
            $team->reset();
        }
        foreach($this->generators as $generator){
            if($generator->getBlockEntity() !== null){
                $generator->getBlockEntity()->flagForDespawn();
            }

            if($generator->getFloatingText() !== null){
                $generator->getFloatingText()->setInvisible(true);
                foreach($this->plugin->getServer()->getOnlinePlayers() as $player){
                    foreach($generator->getFloatingText()->encode() as $packet){
                        $player->dataPacket($packet);
                    }
                }
            }
        }

        $this->spectators = [];
        $this->players = [];
        $this->winnerTeam = '';
        $this->startTime = 30;
        $this->rebootTime = 10;
        $this->generators = array();
        $this->state = self::STATE_LOBBY;
        $this->starting = false;
        $this->plugin->getServer()->unloadLevel($this->plugin->getServer()->getLevelByName($this->worldName));
        $this->reload();
        //lobby register
        $this->setLobby(new Vector3($this->lobby->x, $this->lobby->y, $this->lobby->z), $this->lobbyName);

    }

    public function start() : void{
         $this->broadcastMessage(TextFormat::GREEN . "§e§lEDIT ME KIZU§8 > §eThe Game has Begun!");
         $this->state = self::STATE_RUNNING;

         foreach($this->players as $player){
         $player->addTitle(TextFormat::GOLD . "§a§lGAME STARTED!");
             $playerTeam = $this->plugin->getPlayerTeam($player);

             if($playerTeam == null){
                 $players = array();
                 foreach($this->teams as $name => $object){
                     $players[$name] = count($object->getPlayers());
                 }

                 $lowest = min($players);
                 $teamName = array_search($lowest, $players);

                 $team = $this->teams[$teamName];
                 $team->add($player);
                 $playerTeam = $team;


             }

             $playerTeam->setArmor($player, 'leather');

             $this->respawnPlayer($player);
             $player->setNameTag(TextFormat::BOLD . $playerTeam->getColor() . strtoupper($playerTeam->getName()[0]) . " " .  TextFormat::RESET . $playerTeam->getColor() . $player->getName());

             $this->trackingPositions[$player->getRawUniqueId()] = $playerTeam->getName();
             $player->setSpawn(Utils::stringToVector(":",  $spawnPos = $this->teamInfo[$playerTeam->getName()]['spawnPos']));
         }

         $this->initShops();
         $this->initGenerators();
         $this->initTeams();




    }

    private function initTeams() : void{
        foreach($this->teams as $team){
            if(count($team->getPlayers()) === 0){
                $team->updateBedState(false);
            }
        }
    }

    private function initGenerators() : void{
        foreach($this->generatorInfo as $generator){
            $generatorData = BedWars::GENERATOR_PRIORITIES[$generator['type']];
            $item = $generatorData['item'];
            $spawnText = $generatorData['spawnText'];
            $spawnBlock = $generatorData['spawnBlock'];
            $delay = $generatorData['refreshRate'];

            $vector = Utils::stringToVector(":", $generator['position']);
            $position = new Position($vector->x, $vector->y, $vector->z,$this->plugin->getServer()->getLevelByName($this->worldName));

            $this->generators[] = new Generator($item, $delay,$position, $spawnText, $spawnBlock);


        }
    }

    private function initShops() : void{
        foreach($this->teamInfo as $team => $info){
            $shopPos = Utils::stringToVector(":", $info['shopPos']);
            $rotation = explode(":", $info['shopPos']);

            $nbt = Entity::createBaseNBT($shopPos, null, 2, 2);
            $entity = Entity::createEntity("Villager", $this->plugin->getServer()->getLevelByName($this->worldName), $nbt);
            $entity->setNameTag(TextFormat::BOLD . TextFormat::GOLD . "§eITEM SHOP\n" . TextFormat::YELLOW . "§bTap To Buy!");
            $entity->setNameTagAlwaysVisible(true);
            $entity->spawnToAll();

            $this->npcs[$entity->getId()] = [$team, 'shop'];

            $upgradePos = Utils::stringToVector(":", $info['upgradePos']);
            $rotation = explode(":", $info['upgradePos']);

            $nbt = Entity::createBaseNBT($upgradePos, null, 2, 2);
            $entity = Entity::createEntity("Villager", $this->plugin->getServer()->getLevelByName($this->worldName), $nbt);
            $entity->setNameTag(TextFormat::BOLD . TextFormat::GOLD . "§eITEM UPGRADES\n" . TextFormat::YELLOW . "§bTap To Upgrade!");
            $entity->setNameTagAlwaysVisible(true);
            $entity->spawnToAll();

            $this->npcs[$entity->getId()] = [$team, 'upgrade'];

        }
    }

    /**
     * @param Player $player
     */
    public function join(Player $player) : void{
         if($this->state !== self::STATE_LOBBY){
             $player->sendMessage(BedWars::PREFIX . TextFormat::YELLOW . "§e§lEDIT ME KIZU§8 > §r§cArena is full!");
             return;
         }
         $player->teleport($this->lobby);
         $this->players[$player->getRawUniqueId()] = $player;

         $this->broadcastMessage(TextFormat::AQUA . $player->getName() . " " . TextFormat::YELLOW . "has joined " . TextFormat::YELLOW . "[" . TextFormat::AQUA.  count($this->players) . TextFormat::YELLOW . "/" . TextFormat::AQUA .  $this->maxPlayers . TextFormat::YELLOW .  "]!");
         $player->setFood(20);
         $player->setHealth(20);
         $player->setGamemode(2);
         $player->getInventory()->clearAll();

        $level = $player->getLevel();
        $level->broadcastLevelSoundEvent(new Vector3($player->getX(), $player->getY(), $player->getZ()), LevelSoundEventPacket::SOUND_CONDUIT_ACTIVATE);

         $a = 0;
         $items = array_fill(0, count($this->teams), Item::get(Item::WOOL));
         foreach($this->teams as $team){
             $items[$a]->setDamage(Utils::colorIntoWool($team->getColor()));
             $player->getInventory()->addItem($items[$a]);
             $a++;
         }

         $player->getInventory()->setItem(8, Item::get(Item::COMPASS)->setCustomName(TextFormat::YELLOW . "Leave"));
         $this->checkLobby();

        \BedWars\utils\Scoreboard::new($player, 'bedwars', TextFormat::BOLD . TextFormat::GOLD . "§l§eBED WARS");

        \BedWars\utils\Scoreboard::setLine($player, 1, " ");
        \BedWars\utils\Scoreboard::setLine($player, 2, " " . TextFormat::YELLOW ."§rMap§7: " . TextFormat::WHITE .  $this->worldName . str_repeat(" ", 3));
        \BedWars\utils\Scoreboard::setLine($player, 3, " " . TextFormat::YELLOW . "§rPlayers§7: " . TextFormat::WHITE . count($this->players) . "/" . $this->maxPlayers . str_repeat(" ", 3));
        \BedWars\utils\Scoreboard::setLine($player, 4, "  ");
        \BedWars\utils\Scoreboard::setLine($player, 5, " " . $this->starting ? TextFormat::YELLOW . "Starting in " . TextFormat::GOLD .  $this->startTime . str_repeat(" ", 3) : TextFormat::GOLD . "Waiting for players..." . str_repeat(" ", 3));
        \BedWars\utils\Scoreboard::setLine($player, 6, "   ");
        \BedWars\utils\Scoreboard::setLine($player, 7, " " . TextFormat::WHITE . "Mode§7: " . TextFormat::WHITE . substr(str_repeat($this->playersPerTeam . "v", count($this->teams)), 0, -1) . str_repeat(" ", 3));
        \BedWars\utils\Scoreboard::setLine($player, 8, "    ");
        \BedWars\utils\Scoreboard::setLine($player, 9, " " . TextFormat::YELLOW . "play.pixelbe.cf");

$player->sendMessage("§7=====================================");
		$player->sendMessage("\n");
		$player->sendMessage("                   §l§eBEDWARS!                    ");
		$player->sendMessage("§l§aINFO§8:\n§r§c this is a game about beds having a war to finally sleep in peace, you will spawn with either a teammate if your playing any other mode besides duels or solo, try to break the enemies bed while you have§l§6 UNLIMITED timing§r§b! And there will be an ender dragon, trying to ruin you and the players victory! Break the enemies bed so they can no longer spawn and than try to kill them so they no longer exist within the game.");

		$player->sendMessage("\n");
		$player->sendMessage("§7=====================================");

    }

    /**
     * @param Player $player
     */
    public function trackCompass(Player $player) : void{
        $currentTeam = $this->trackingPositions[$player->getRawUniqueId()];
        $arrayTeam = $this->teams;
        $position = array_search($currentTeam, array_keys($arrayTeam));
        $teams = array_values($this->teams);
        $team = null;

        if(isset($teams[$position+1])){
            $team = $teams[$position+1]->getName();
        }else{
            $team = $teams[0]->getName();
        }

        $this->trackingPositions[$player->getRawUniqueId()] = $team;

        $player->setSpawn(Utils::stringToVector(":",  $spawnPos = $this->teamInfo[$team]['spawnPos']));
        $player->setSpawn(Utils::stringToVector(":",  $spawnPos = $this->teamInfo[$team]['spawnPos']));

        foreach($player->getInventory()->getContents() as $slot => $item){
            if($item instanceof Compass){
                $player->getInventory()->removeItem($item);
                $player->getInventory()->setItem($slot, Item::get(Item::COMPASS)->setCustomName(TextFormat::GOLD . "Tap To Switch"));
            }
        }

    }

    /**
     * @param Team $team
     * @param Player $player
     */
    public function breakBed(Team $team, Player $player) : void{
        $team->updateBedState(false);
        
        $playerTeam = $this->plugin->getPlayerTeam($player);

        $this->broadcastMessage($team->getColor() . $team->getName() . "'s '" . TextFormat::YELLOW . "bed was destroyed by " . $playerTeam->getColor() . $player->getName());
     $level = $player->getLevel();
     $level->broadcastLevelSoundEvent(new Vector3($player->getX(), $player->getY(), $player->getZ()), LevelSoundEventPacket::SOUND_NOTE);

        //TODO: bed.yml config
        $bkconfig = new Config("plugin_data/BedWars/bed.yml", Config::YAML);
        $bkconfig->getAll();
        $bkconfig->set($player->getName(), $bkconfig->get($player->getName()) + 1);
        $bkconfig->save();

        foreach($team->getPlayers() as $player){
            $level = $player->getLevel();
            $level->broadcastLevelSoundEvent(new Vector3($player->getX(), $player->getY(), $player->getZ()), LevelSoundEventPacket::SOUND_BULLET_HIT);
            $player->addTitle(TextFormat::RED . "§c§lBED DESTROY!!", TextFormat::RED . "§r§7You will no longer respawn");
        }
    }

    /**
     * @param Player $player
     */
    public function quit(Player $player) : void{
        $player->getInventory()->clearAll();
        $player->getArmorInventory()->clearAll();
        $player->getCursorInventory()->clearAll();
        $player->setFood(20);
        $player->setHealth(20);
 
            $level = $player->getLevel();
            $level->broadcastLevelSoundEvent(new Vector3($player->getX(), $player->getY(), $player->getZ()), LevelSoundEventPacket::SOUND_SPARKLER_ACTIVE);
 
         if(isset($this->players[$player->getRawUniqueId()])){
             unset($this->players[$player->getRawUniqueId()]);
          $player->setGamemode(0);
         }
        $player->getInventory()->clearAll();
        $player->getArmorInventory()->clearAll();
        $player->getCursorInventory()->clearAll();
        $player->setFood(20);
        $player->setHealth(20);

         if(isset($this->spectators[$player->getRawUniqueId()])){
             unset($this->spectators[$player->getRawUniqueId()]);
         $player->setGamemode(0);
         }


         \BedWars\utils\Scoreboard::remove($player);
    }

    private function checkLobby() : void{
        if(!$this->starting && count($this->players) >= $this->minPlayers){
            $this->starting = true;
            $this->broadcastMessage(TextFormat::YELLOW . "§l§eCountdown started");
        }elseif($this->starting && count($this->players) < $this->minPlayers){
            $this->starting = false;
            $this->broadcastMessage(TextFormat::YELLOW . "§l§cCountdown stopped");
        }
    }

    /**
     * @param Player $player
     */
    public function killPlayer(Player $player) : void{
        $playerTeam = $this->plugin->getPlayerTeam($player);
        if($player->isSpectator())return;
        
        $fconfig = new Config("plugin_data/BWKills/stats/levels/Levels.yml", Config::YAML);
        $fconfig->getAll();
    
         $kconfig = new Config("plugin_data/BWFkills/stats/levels/Levels.yml", Config::YAML);
         $kconfig->getAll();


        $config = new Config("plugin_data/BedWars/kills.yml", Config::YAML);
        $config->getAll();

        $xconfig = new Config("plugin_data/BedWars/Fkills.yml", Config::YAML);
        $xconfig->getAll();

        if(!$playerTeam->hasBed()){
            $playerTeam->dead++;
            $this->spectators[$player->getRawUniqueId()] = $player;
            unset($this->players[$player->getRawUniqueId()]);
            $player->setGamemode(Player::SPECTATOR);
            $player->addTitle(TextFormat::BOLD . TextFormat::RED . "§c§lYOU DIED!", TextFormat::GRAY . "§r§7You can no longer respawn");
            
            $level = $player->getLevel();
            $level->broadcastLevelSoundEvent(new Vector3($player->getX(), $player->getY(), $player->getZ()), LevelSoundEventPacket::SOUND_BLOCK_TURTLE_EGG_HATCH);

        }else{
            $player->setGamemode(Player::SPECTATOR);
            $this->deadQueue[$player->getRawUniqueId()] = 10;
         }

        $cause = $player->getLastDamageCause();
        if($cause == null)return; //probadly handled the event itself 
        switch($cause->getCause()){
            case EntityDamageEvent::CAUSE_ENTITY_ATTACK;
            $damager = $cause->getDamager();
            $this->broadcastMessage($this->plugin->getPlayerTeam($player)->getColor() . $player->getName() . " " . TextFormat::YELLOW . "was killed by " . $this->plugin->getPlayerTeam($damager)->getColor() . $damager->getName());
      
        $player->getInventory()->clearAll();
        $player->getArmorInventory()->clearAll();
        $player->getCursorInventory()->clearAll();
        $player->setFood(20);
        $player->setHealth(20);
        $player->setGamemode(3);
        
            $level = $player->getLevel();
            $level->broadcastLevelSoundEvent(new Vector3($player->getX(), $player->getY(), $player->getZ()), LevelSoundEventPacket::SOUND_BLOCK_TURTLE_EGG_HATCH);

$config->set($damager->getName(), $config->get($damager->getName()) + 1);
        $config->save();

$kconfig->set($damager->getName(), $kconfig->get($damager->getName()) + 1);
        $kconfig->save();
            
   if(!$playerTeam->hasBed()){
       
       
            $level = $player->getLevel();
            $level->broadcastLevelSoundEvent(new Vector3($player->getX(), $player->getY(), $player->getZ()), LevelSoundEventPacket::SOUND_BLOCK_TURTLE_EGG_HATCH);

         $player->getInventory()->clearAll();
        $player->getArmorInventory()->clearAll();
        $player->getCursorInventory()->clearAll();
        $player->setFood(20);
        $player->setHealth(20);
        $player->setGamemode(3);
 
$fconfig->set($damager->getName(), $fconfig->get($damager->getName()) + 1);
        $fconfig->save();

$xconfig->set($damager->getName(), $xconfig->get($damager->getName()) + 1);
        $xconfig->save();


   }
            break;
            case EntityDamageEvent::CAUSE_PROJECTILE;
            if($cause instanceof EntityDamageByChildEntityEvent){
                $damager = $cause->getDamager();
                $this->broadcastMessage($this->plugin->getPlayerTeam($player)->getColor() . $player->getName() . " " . TextFormat::YELLOW . "was shot by " . $this->plugin->getPlayerTeam($damager)->getColor() . $damager->getName());

            $level = $player->getLevel();
            $level->broadcastLevelSoundEvent(new Vector3($player->getX(), $player->getY(), $player->getZ()), LevelSoundEventPacket::SOUND_BLOCK_TURTLE_EGG_HATCH);

         $player->getInventory()->clearAll();
        $player->getArmorInventory()->clearAll();
        $player->getCursorInventory()->clearAll();
        $player->setFood(20);
        $player->setHealth(20);
        $player->setGamemode(3);
            
$config->set($damager->getName(), $config->get($damager->getName()) + 1);
        $config->save();

$kconfig->set($damager->getName(), $kconfig->get($damager->getName()) + 1);
        $kconfig->save();
            
   if(!$playerTeam->hasBed()){
            $level = $player->getLevel();
            $level->broadcastLevelSoundEvent(new Vector3($player->getX(), $player->getY(), $player->getZ()), LevelSoundEventPacket::SOUND_BLOCK_TURTLE_EGG_HATCH);

         $playerTeam->getInventory()->clearAll();
        $playerTeam->getArmorInventory()->clearAll();
        $playerTeam->getCursorInventory()->clearAll();
        $playerTeam->setFood(20);
        $playerTeam->setHealth(20);
        $playerTeam->setGamemode(3);
       
$fconfig->set($damager->getName(), $fconfig->get($damager->getName()) + 1);
        $fconfig->save();

$xconfig->set($damager->getName(), $xconfig->get($damager->getName()) + 1);
        $xconfig->save();


   }            }
            break;
            case EntityDamageEvent::CAUSE_FIRE;
            $this->broadcastMessage($this->plugin->getPlayerTeam($player)->getColor() . $player->getName() . " " . TextFormat::YELLOW . "went up in flame");
        $level = $player->getLevel();
        $level->broadcastLevelSoundEvent(new Vector3($player->getX(), $player->getY(), $player->getZ()), LevelSoundEventPacket::SOUND_BLOCK_TURTLE_EGG_HATCH);

        $player->getInventory()->clearAll();
        $player->getArmorInventory()->clearAll();
        $player->getCursorInventory()->clearAll();
        $player->setFood(20);
        $player->setHealth(20);
        $player->setGamemode(3);

            break;
            case EntityDamageEvent::CAUSE_VOID;
            $level = $player->getLevel();
            $level->broadcastLevelSoundEvent(new Vector3($player->getX(), $player->getY(), $player->getZ()), LevelSoundEventPacket::SOUND_BLAST);

        $player->teleport($player->add(0, $this->voidY + 5, 0));
$player->getInventory()->clearAll();
        $player->getArmorInventory()->clearAll();
        $player->getCursorInventory()->clearAll();
        $player->setFood(20);
        $player->setHealth(20);
        $player->setGamemode(3);

            break;
        }
    }



    /**
     * @param Player $player
     */
    public function respawnPlayer(Player $player) : void{
        $team = $this->plugin->getPlayerTeam($player);
        //Should handle some changes
        if($team == null)return;
        //FIX THE SHOP PROBLEM
         $this->initShops();

        $spawnPos = $this->teamInfo[$team->getName()]['spawnPos'];

        $player->setGamemode(Player::SURVIVAL);
        $player->setFood($player->getMaxFood());
        $player->setHealth($player->getMaxHealth());
        $player->getInventory()->clearAll();

        $player->teleport($this->plugin->getServer()->getLevelByName($this->worldName)->getSafeSpawn());
        $player->teleport(Utils::stringToVector(":", $spawnPos));

        //inventory
        $helmet = Item::get(Item::LEATHER_CAP);
        $chestplate = Item::get(Item::LEATHER_CHESTPLATE);
        $leggings = Item::get(Item::LEATHER_LEGGINGS);
        $boots = Item::get(Item::LEATHER_BOOTS);

        $hasArmorUpdated = true;

        switch($team->getArmor($player)){
            case "iron";
            $leggings = Item::get(Item::IRON_LEGGINGS);
            break;
            case "diamond";
            $boots = Item::get(Item::IRON_BOOTS);
            break;
            default;
            $hasArmorUpdated = false;
            break;
        }


        foreach(array_merge([$helmet, $chestplate], !$hasArmorUpdated ? [$leggings, $boots] : []) as $armor){
            $armor->setCustomColor(Utils::colorIntoObject($team->getColor()));
        }

        $armorUpgrade = $team->getUpgrade('armorProtection');
        if($armorUpgrade > 0){
            foreach([$helmet, $chestplate, $leggings, $boots] as $armor){
                $armor->addEnchantment(new EnchantmentInstance(Enchantment::getEnchantment(Enchantment::PROTECTION)), $armorUpgrade);
            }
        }

        $player->getArmorInventory()->setHelmet($helmet);
        $player->getArmorInventory()->setChestplate($chestplate);
        $player->getArmorInventory()->setLeggings($leggings);
        $player->getArmorInventory()->setBoots($boots);

        $sword = Item::get(Item::WOODEN_SWORD);

        $swordUpgrade = $team->getUpgrade('sharpenedSwords');
        if($swordUpgrade > 0){
            $sword->addEnchantment(new EnchantmentInstance(Enchantment::getEnchantment(Enchantment::SHARPNESS)), $swordUpgrade);
        }

        $player->getInventory()->setItem(0, $sword);
        $player->getInventory()->setItem(8, Item::get(Item::COMPASS)->setCustomName(TextFormat::GOLD . "Tap To Switch!"));

    }


    public function tick() : void{

         switch($this->state) {
             case self::STATE_LOBBY;
                 if ($this->starting) {
                     $this->startTime--;

                     foreach ($this->players as $player) {
                         $player->sendTip(TextFormat::YELLOW . "§l§eBedWars§r§b Starting in§7 " . gmdate("i:s", $this->startTime));
                     $level = $player->getLevel();
                         $level->broadcastLevelSoundEvent(new Vector3($player->getX(), $player->getY(), $player->getZ()), LevelSoundEventPacket::SOUND_BLOCK_BARREL_CLOSE);

                     }

                     switch ($this->startTime) {
                         case 30;
                             $this->broadcastMessage(TextFormat::YELLOW . "§r§eGame Starting in " . TextFormat::GOLD . "§c30");
                             $player->broadcastLevelSoundEvent(new Vector3($player->getX(), $player->getY(), $player->getZ()), LevelSoundEventPacket::SOUND_BLOCK_BARREL_CLOSE);

                             break;
                         case 15;
                             $this->broadcastMessage(TextFormat::YELLOW . "§r§eGame Starting in " . TextFormat::GOLD . "§c15");
                             $level->broadcastLevelSoundEvent(new Vector3($player->getX(), $player->getY(), $player->getZ()), LevelSoundEventPacket::SOUND_BLOCK_BARREL_CLOSE);

                             break;
                         case 10;
                         $level->broadcastLevelSoundEvent(new Vector3($player->getX(), $player->getY(), $player->getZ()), LevelSoundEventPacket::SOUND_BLOCK_BARREL_CLOSE);
                         case 5;
                         case 4;
                         case 3;
                         case 2;
                         case 1;
                             foreach ($this->players as $player) {
                     $level = $player->getLevel();
                     $level->broadcastLevelSoundEvent(new Vector3($player->getX(), $player->getY(), $player->getZ()), LevelSoundEventPacket::SOUND_BOTTLE_DRAGONBREATH);

                                 $player->addTitle(TextFormat::GOLD . "§c" . $this->startTime, "§bGet ready!", 2, 20, 0);
                             }
                             break;
                     }

                     if ($this->startTime == 0) {
                         $this->start();
                     }
                 } else {
                     foreach ($this->players as $player) {
                         $player->sendTip(TextFormat::YELLOW . "§lBEDWARS\n§r§aWaiting for players (" . TextFormat::AQUA . ($this->minPlayers - count($this->players)) . TextFormat::YELLOW . ")");
                     }
                 }

                 foreach (array_merge($this->players, $this->spectators) as $player) {
                     \BedWars\utils\Scoreboard::remove($player);
       
            $player->setFood(20);
                     \BedWars\utils\Scoreboard::new($player, 'bedwars', TextFormat::BOLD . TextFormat::GOLD . "§l§eBED WARS");
                     \BedWars\utils\Scoreboard::setLine($player, 1, " ");
                     \BedWars\utils\Scoreboard::setLine($player, 2, " " . TextFormat::WHITE . "Map§7: " . TextFormat::GREEN . $this->worldName . str_repeat(" ", 3));
                     \BedWars\utils\Scoreboard::setLine($player, 3, " " . TextFormat::WHITE . "Players§7: " . TextFormat::GREEN . count($this->players) . "/" . $this->maxPlayers . str_repeat(" ", 3));
                     \BedWars\utils\Scoreboard::setLine($player, 4, "  ");
                     \BedWars\utils\Scoreboard::setLine($player, 5, " " . ($this->starting ? TextFormat::WHITE . "Starting in " . TextFormat::GREEN . $this->startTime . str_repeat(" ", 3) : TextFormat::GREEN . "Waiting for players...§e" . str_repeat(" ", 3)));
                     \BedWars\utils\Scoreboard::setLine($player, 6, "   ");
                     \BedWars\utils\Scoreboard::setLine($player, 7, " " . TextFormat::WHITE . "Mode§7: " . TextFormat::GREEN . substr(str_repeat($this->playersPerTeam . "v", count($this->teams)), 0, -1) . str_repeat(" ", 3));
                     \BedWars\utils\Scoreboard::setLine($player, 8, "    ");
                     \BedWars\utils\Scoreboard::setLine($player, 9, " " . TextFormat::YELLOW . "play.pixelbe.cf");
                 }

                 break;
             case self::STATE_RUNNING;

                 foreach ($this->players as $player) {
                     if ($player->getInventory()->contains(Item::get(Item::COMPASS))) {
                         $trackIndex = $this->trackingPositions[$player->getRawUniqueId()];
                         $team = $this->teams[$trackIndex];
                        
                         $player->setGamemode(0);
                         $player->sendTip(TextFormat::WHITE . "§eTracking: " . TextFormat::BOLD . $team->getColor() . ucfirst($team->getName()) . " " . TextFormat::RESET . TextFormat::WHITE . "- §eDistance: " . TextFormat::BOLD . $team->getColor() . round(Utils::stringToVector(":", $this->teamInfo[$trackIndex]['spawnPos'])->distance($player)) . "m");
                     }

                     if (isset($this->deadQueue[$player->getRawUniqueId()])) {
                         if($player->isSurvival())return;
                         $player->addTitle(TextFormat::RED . "§lYOU DIED!", TextFormat::YELLOW . "§cYou will respawn in " . TextFormat::RED . $this->deadQueue[$player->getRawUniqueId()] . " " . TextFormat::YELLOW . "seconds!");
                         $player->sendMessage(TextFormat::YELLOW . "§eYou will respawn in§6 " . TextFormat::RED . $this->deadQueue[$player->getRawUniqueId()] . " " . TextFormat::YELLOW . "§eseconds!");

                         $this->deadQueue[$player->getRawUniqueId()] -= 2;
                         if ($this->deadQueue[$player->getRawUniqueId()] == 0) {
                             unset($this->deadQueue[$player->getRawUniqueId()]);

                             $this->respawnPlayer($player);
                             $player->addTitle(TextFormat::GREEN . "§l§eRESPAWNED!");
                             $player->sendMessage(TextFormat::YELLOW . "§aYou have respawned!");
                         }
                     }
                 }

                 foreach (array_merge($this->players, $this->spectators) as $player) {

            \BedWars\utils\Scoreboard::remove($player);
         
         $fconfig = new Config("plugin_data/BedWars/kills.yml", Config::YAML);
$fconfig->getAll();

        $xconfig = new Config("plugin_data/BedWars/Fkills.yml", Config::YAML);
$xconfig->getAll();

        $bkconfig = new Config("plugin_data/BedWars/bed.yml", Config::YAML);
$bkconfig->getAll();
             //REDUCE FOOD
            $player->setFood(20);
        
\BedWars\utils\Scoreboard::new($player, 'bedwars', TextFormat::BOLD . TextFormat::YELLOW . "§e§lBED WARS");

\BedWars\utils\Scoreboard::setLine($player, 1, " §7" . date("d/m/Y"));
                     \BedWars\utils\Scoreboard::setLine($player, 2, " ");
                     \BedWars\utils\Scoreboard::setLine($player, 3, " " . TextFormat::YELLOW . ucfirst($this->tierUpdateGen) . " Upgrade: " . TextFormat::GRAY . gmdate("i:s", $this->tierUpdate));
                     \BedWars\utils\Scoreboard::setLine($player, 4, "  ");

                     $currentLine = 5;
                     $playerTeam = $this->plugin->getPlayerTeam($player);
                     foreach ($this->teams as $team) {
                         $status = "";
                         if ($team->hasBed()) {
                             $status = TextFormat::GREEN . "§l+§r";
                         } elseif(count($team->getPlayers()) < $team->dead) {
                             $status = count($team->getPlayers()) === 0 ? TextFormat::WHITE . "§lALIVE§r" : TextFormat::GRAY . "[" . count($team->getPlayers()) . "]";
                         }elseif(count($team->getPlayers()) >= $team->dead){
                             $status = TextFormat::DARK_RED . "§l×§r";
                         }
                         $isPlayerTeam = $team->getName() == $playerTeam->getName() ? TextFormat::GRAY . "(YOU)" : "";
                         $stringFormat = TextFormat::BOLD . $team->getColor() . ucfirst($team->getName()[0]) . " " . TextFormat::RESET . TextFormat::WHITE . ucfirst($team->getName()) . ": " . $status . " " . $isPlayerTeam;
                         \BedWars\utils\Scoreboard::setLine($player, $currentLine, " " . $stringFormat);
                         $currentLine++;
                     }
\BedWars\utils\Scoreboard::setLine($player, 10, "          ");
                    
\BedWars\utils\Scoreboard::setLine($player, 11, "§rYour kills§7: " . TextFormat::GREEN . $fconfig->get($player->getName(), 0) . str_repeat(" ", 3));

\BedWars\utils\Scoreboard::setLine($player, 12, "§rFinal kills§7: " . TextFormat::GREEN . $xconfig->get($player->getName(), 0) . str_repeat(" ", 3));
\BedWars\utils\Scoreboard::setLine($player, 13, "§rBed broken§7: " . TextFormat::GREEN . $bkconfig->get($player->getName(), 0) . str_repeat(" ", 3));

                 \BedWars\utils\Scoreboard::setLine($player, 14, "   ");
                     \BedWars\utils\Scoreboard::setLine($player, 15, " " . TextFormat::YELLOW . "play.pixelbe.cf");
                 }
                 

                 
            // if(count($team = $this->getAliveTeams) === 1){
            if(count($team = $this->getAliveTeams()) === 1){

                 $this->winnerTeam = $team[0];

                 $this->state = self::STATE_REBOOT;
             } else 
             {
        //i am done with phpstorm lol
        }
             
             foreach($this->generators as $generator){
                 $generator->tick();
             }

             $this->tierUpdate --;

             if($this->tierUpdate == 0){
                 $this->tierUpdate = 600 * 10;
                 foreach($this->generators as $generator){
                     if($generator->itemID == Item::DIAMOND && $this->tierUpdateGen == "diamond") {
                          $generator->updateTier();
                     }elseif($generator->itemID == Item::EMERALD && $this->tierUpdateGen == "emerald"){
                          $generator->updateTier();
                     }
                 }
                 $this->tierUpdateGen = $this->tierUpdateGen == 'diamond' ? 'emerald' : 'diamond';

             }
         
             break;
             case Game::STATE_REBOOT;
             foreach (array_merge($this->players, $this->spectators) as $player) {
            $player->setFood(20);
            $player->setHealth(20);
            $player->setGamemode(0);
            EconomyAPI::getInstance()->addMoney($player, 10);
	     	$player->setHealth($player->getMaxHealth());                                $player->getInventory()->clearAll();
            $player->getArmorInventory()->clearAll();
            $player->getCursorInventory()->clearAll();
            //REMOVING THE DATA		 
		    $config = new Config("plugin_data/BedWars/kills.yml", Config::YAML);
            $config->getAll();
            $config->set($player->getName(), $config->remove($player->getName(), "               "));
            $config->set($player->getName(), $config->remove($player->getName(), "0"));
            $config->save();
        
            $xconfig = new Config("plugin_data/BedWars/Fkills.yml", Config::YAML);
            $xconfig->getAll();
            $xconfig->set($player->getName(), $xconfig->remove($player->getName(), "               "));
             $xconfig->set($player->getName(), $xconfig->remove($player->getName(), "0"));
             $xconfig->save();
        
             $bkconfig = new Config("plugin_data/BedWars/bed.yml", Config::YAML);
             $bkconfig->set($player->getName(), $bkconfig->remove($player->getName(), "               "));
             $bkconfig->set($player->getName(), $bkconfig->remove($player->getName(), "0"));
             $bkconfig->getAll();
        
             $level = $player->getLevel();
             $level->broadcastLevelSoundEvent(new Vector3($player->getX(), $player->getY(), $player->getZ()), LevelSoundEventPacket::SOUND_BLAST);

           
             --$this->rebootTime;
             $team = $this->teams[$this->winnerTeam];
             
             foreach($team->getPlayers() as $player){
            //WINNER TEAM
	     	$player->setHealth($player->getMaxHealth());                                $player->getInventory()->clearAll();
            $player->getArmorInventory()->clearAll();
            $player->getCursorInventory()->clearAll();
            $player->setFood(20);
            $player->setHealth(20);
            $player->setGamemode(0);
            EconomyAPI::getInstance()->addMoney($player, 110);
                 
            $player->addTitle("§l§6VICTORY!", "§7You were the last man standing!");
        //ITS GONNA SPAM THEM
		$player->sendMessage("§7=====================================");
		$player->sendMessage("\n");
		$player->sendMessage("                   §l§eBEDWARS!                    ");
	//	$player->sendMessage("§eYou have earned §l§a+§6100 §bCOINS§r§e for Winning!");
		$player->sendMessage("§eYou have earned §l§a+§610 §bcoins§r§e for Trying out bedwars!");
		$player->sendMessage("§eYou have earned §l§a+§610 §dEXP§r§e for your Plays!");
		$player->sendMessage("  ");

		$player->sendMessage("\n");
		$player->sendMessage("§7=====================================");
		$player->sendMessage("§eYou have earned §l§a+§6110 §bcoins§r§e and §a§l§310§§d EXP");
	
		     //CLEAR KILLS
		    $config = new Config("plugin_data/BedWars/kills.yml", Config::YAML);
            $config->getAll();
            $config->set($player->getName(), $config->remove($player->getName(), "               "));
            $config->set($player->getName(), $config->remove($player->getName(), "0"));
            $config->save();
            //CLEARS FINAL KILLS
            $xconfig = new Config("plugin_data/BedWars/Fkills.yml", Config::YAML);
            $xconfig->getAll();
            $xconfig->set($player->getName(), $xconfig->remove($player->getName(), "               "));
             $xconfig->set($player->getName(), $xconfig->remove($player->getName(), "0"));
             $xconfig->save();
             //CLEARS BED
             $bkconfig = new Config("plugin_data/BedWars/bed.yml", Config::YAML);
             $bkconfig->set($player->getName(), $bkconfig->remove($player->getName(), "               "));
             $bkconfig->set($player->getName(), $bkconfig->remove($player->getName(), "0"));
             $bkconfig->getAll();
             //ADDS A WIN
             $winconfig = new Config("plugin_data/BWWins/stats/levels/Levels.yml", Config::YAML);
             $winconfig->set($player->getName(), $winconfig->get($player->getName()) + 1);
             $winconfig->save();
            
             $x = $player->getX();
             $y = $player->getY();
             $z = $player->getZ();
                
        $red = new DustParticle(new Vector3($x, $y, $z), 252, 17, 17);
        $green = new DustParticle(new Vector3($x, $y, $z), 102, 153, 102);
        $flame = new FlameParticle(new Vector3($x, $y, $z));
        
        $level = $player->getLevel();
               
                foreach([$red, $green, $flame] as $particle) {
                   
    $level->addParticle($particle);
    $pos = $this->getPosition();
                    
    $red = new DustParticle($pos->add(0, 8.5), 252, 17, 17);
    
    $orange = new DustParticle($pos->add(0, 3.1), 252, 135, 17);
    
    $yellow = new DustParticle($pos->add(0, 3.7), 252, 252, 17);
    
    $green = new DustParticle($pos->add(0, 5.3), 17, 252, 17);
    
    $lblue = new DustParticle($pos->add(0, 0.9), 94, 94, 252);
    
    $dblue = new DustParticle($pos->add(0, 0.5), 17, 17, 252);
    
    foreach ([$red, $orange, $yellow, $green, $lblue, $dblue] as $particle) {
    $pos->getLevel()->addParticle($particle);

                       }
                   }
               }
           }
             if($this->rebootTime == 0){
                 $this->stop();
                }
             break;
            }
      }
}