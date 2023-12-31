<?php

declare(strict_types=1);


namespace BedWars;


use pocketmine\block\utils\SignText;
use pocketmine\item\Item;
use pocketmine\level\Level;
use pocketmine\Player;
use pocketmine\plugin\PluginBase;
use pocketmine\scheduler\Task;
use pocketmine\tile\Sign;
use pocketmine\utils\TextFormat;
use pocketmine\math\Vector3;
use BedWars\command\DefaultCommand;
use BedWars\game\Game;
use BedWars\game\GameListener;
use BedWars\game\Team;


class BedWars extends PluginBase
{

    const PREFIX = TextFormat::BOLD . TextFormat::DARK_RED . "§r" . TextFormat::RESET;

    /** @var Game[] $games */
    public $games = array();

    /** @var array $signs */
    public $signs = array();

    /** @var array $bedSetup */
    public $bedSetup = array();

    const TEAMS = [
        'blue' => "§1",
        'red' => "§c",
        'yellow' => "§e",
        "green" => "§a",
        "aqua" => "§b",
        "gold" => "§6",
        "white" => "§f"
    ];

    const GENERATOR_PRIORITIES = [
        'gold' => ['item' => Item::GOLD_INGOT, 'spawnText' => false, 'spawnBlock' => false, 'refreshRate' => 10],
        'iron' => ['item' => Item::IRON_INGOT, 'spawnText' => false, 'spawnBlock' => false, 'refreshRate' => 3],
        'diamond' => ['item' => Item::DIAMOND, 'spawnText' => true, 'spawnBlock' => true, 'refreshRate' => 30],
        'emerald' => ['item' => Item::EMERALD, 'spawnText' => true, 'spawnBlock' => true, 'refreshRate' => 60]
    ];


    public function onEnable() : void
    {
        @mkdir($this->getDataFolder());
        @mkdir($this->getDataFolder() . "arenas");

        //load arenas

        $this->signTick();
        $this->getServer()->getPluginManager()->registerEvents(new GameListener($this), $this);

        foreach(glob($this->getDataFolder() . "arenas/*.json") as $location){
            $fileContents = file_get_contents($location);
            $jsonData = json_decode($fileContents, true);

            if(!$this->validateGame($jsonData)){
                $arenaName = basename($fileContents, ".json");
                $this->getLogger()->info(self::PREFIX . TextFormat::YELLOW . "Failed to load arena " . $arenaName . " because it's data are corrupted!");
                continue;
            }

            if(count($jsonData['signs']) > 0){
                $this->signs[$jsonData['name']] = $jsonData['signs'];
            }


            $this->games[$jsonData['name']] = $game = new Game($this, $jsonData['name'], intval($jsonData['minPlayers']), intval($jsonData['playersPerTeam']), $jsonData['world'], $jsonData['lobby'], $jsonData['teamInfo'], $jsonData['generatorInfo']);

            if(!empty($jsonData['lobby'])){
                $split = explode(":", $jsonData['lobby']);
                $game->setLobby(new Vector3(intval($split[0]), intval($split[1]), intval($split[2])), $split[3]);
            }

            if(!empty($jsonData['void_y'])){
                $game->setVoidLimit(intval($jsonData['void_y']));
            }
        }


        //load commands
        $this->getServer()->getCommandMap()->register("bedwars", new DefaultCommand($this));
    }

    /**
     * @param string $gameName
     * @return array|null
     */
    public function getArenaData(string $gameName) : ?array{
        if(!$this->gameExists($gameName))return null;

        $location = $this->getDataFolder() . "arenas/" . $gameName . ".json";

        $file = file_get_contents($location);
        return json_decode($file, true);
    }

    /**
     * @param string $gameName
     * @param array $gameData
     * @return void
     */
    public function writeArenaData(string $gameName, array $gameData) : void{
        $location = $this->getDataFolder() . "arenas/" . $gameName . ".json";

        file_put_contents($location, json_encode($gameData));
    }

    /**
     * @param string $gameName
     * @return bool
     */
    public function gameExists(string $gameName) : bool {
        $location = $this->getDataFolder() . "arenas/" . $gameName . ".json";
        if(!is_file($location)){
            return false;
        }

        return true;
    }

    /**
     * @param string $gameName
     * @return bool
     */
    public function isGameLoaded(string $gameName) : bool{
        return isset($this->games[$gameName]);
    }

    /**
     * @param array $arenaData
     * @return bool
     */
    public function validateGame(array $arenaData) : bool{
        $requiredParams = [
            'name',
            'minPlayers',
            'playersPerTeam',
            'lobby',
            'world',
            'teamInfo',
            'generatorInfo'
        ];

        $error = 0;
        foreach($requiredParams as $param){
            if(!in_array($param, array_keys($arenaData))){
                $error ++;
            }
        }

        return !$error > 0;
    }

    private function signTick() : void{
        $this->getScheduler()->scheduleRepeatingTask(
            new class($this) extends Task{

                /** @var BedWars $plugin */
                private $plugin;

                public function __construct(BedWars $plugin)
                {
                    $this->plugin = $plugin;
                }

                /**
                 * @param int $currentTick
                 */
                public function onRun(int $currentTick) : void
                {
                    foreach ($this->plugin->signs as $arena => $positions) {
                        foreach ($positions as $position) {
                            $pos = explode(":", $position);
                            $vector = new Vector3(intval($pos[0]), intval($pos[1]), intval($pos[2]));

                            $level = $this->plugin->getServer()->getLevelByName($pos[3]);

                            if (!$level instanceof Level) {
                                continue;
                            }

                            if (!in_array($arena, array_keys($this->plugin->games))) {
                                continue;
                            }

                            $game = $this->plugin->games[$arena];
                            $tile = $level->getTile($vector);
                            if (!$tile instanceof Sign) {
                                continue;
                            }

                            $tile->setText(TextFormat::BOLD . TextFormat::GOLD . "§c[§eBedWars§c]",
                                TextFormat::BOLD . TextFormat::AQUA . $game->getName(),
                                TextFormat::AQUA . "§a" . count($game->players) . "§7/§a" . $game->getMaxPlayers(),
                                $this->getStatus($game->getState()));


                        }
                    }
                }

                /**
                 * @param int $state
                 * @return string
                 */
                public function getStatus(int $state) : string{
                    switch($state){
                        case 0;
                        return TextFormat::WHITE . "Tap to join";
                        case 1;
                        return TextFormat::RED . "§lRunning";
                    }
                    return "";
                }


            }, 20
        );
    }

    /**
     * @param Player $player
     * @param bool $isSpectator
     * @return Game|null
     */
    public function getPlayerGame(Player $player, bool $isSpectator = false) : ?Game{
        $isSpectator = false;
        foreach($this->games as $game){
            if(isset($game->players[$player->getRawUniqueId()]))return $game;
            if(isset($game->spectators[$player->getRawUniqueId()]))return $game;
        }
        return null;
    }

    /**
     * @param Player $player
     * @return Team|null
     */
    public function getPlayerTeam(Player $player) : ?Team{
        $game = $this->getPlayerGame($player);
        if($game == null)return null;

        foreach($game->teams as $team){
            if(in_array($player->getRawUniqueId(), array_keys($team->getPlayers()))){
                return $team;
            }
        }
        return null;
    }



}