<?php

/*
 *
 *  ____            _        _   __  __ _                  __  __ ____
 * |  _ \ ___   ___| | _____| |_|  \/  (_)_ __   ___      |  \/  |  _ \
 * | |_) / _ \ / __| |/ / _ \ __| |\/| | | '_ \ / _ \_____| |\/| | |_) |
 * |  __/ (_) | (__|   <  __/ |_| |  | | | | | |  __/_____| |  | |  __/
 * |_|   \___/ \___|_|\_\___|\__|_|  |_|_|_| |_|\___|     |_|  |_|_|
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * @author PocketMine Team
 * @link http://www.pocketmine.net/
 *
 *
*/

/**
 * Network-related classes
 */
namespace softmine\network;

use softmine\network\protocol\AddEntityPacket;
use softmine\network\protocol\AddItemEntityPacket;
use softmine\network\protocol\AddPaintingPacket;
use softmine\network\protocol\AddPlayerPacket;
use softmine\network\protocol\AdventureSettingsPacket;
use softmine\network\protocol\AnimatePacket;
use softmine\network\protocol\BatchPacket;
use softmine\network\protocol\ContainerClosePacket;
use softmine\network\protocol\ContainerOpenPacket;
use softmine\network\protocol\ContainerSetContentPacket;
use softmine\network\protocol\ContainerSetDataPacket;
use softmine\network\protocol\ContainerSetSlotPacket;
use softmine\network\protocol\CraftingDataPacket;
use softmine\network\protocol\CraftingEventPacket;
use softmine\network\protocol\DataPacket;
use softmine\network\protocol\DropItemPacket;
use softmine\network\protocol\FullChunkDataPacket;
use softmine\network\protocol\Info;
use softmine\network\protocol\SetEntityLinkPacket;
use softmine\network\protocol\BlockEntityDataPacket;
use softmine\network\protocol\EntityEventPacket;
use softmine\network\protocol\ExplodePacket;
use softmine\network\protocol\HurtArmorPacket;
use softmine\network\protocol\Info as ProtocolInfo;
use softmine\network\protocol\InteractPacket;
use softmine\network\protocol\LevelEventPacket;
use softmine\network\protocol\DisconnectPacket;
use softmine\network\protocol\LoginPacket;
use softmine\network\protocol\PlayStatusPacket;
use softmine\network\protocol\TextPacket;
use softmine\network\protocol\MoveEntityPacket;
use softmine\network\protocol\MovePlayerPacket;
use softmine\network\protocol\PlayerActionPacket;
use softmine\network\protocol\MobArmorEquipmentPacket;
use softmine\network\protocol\MobEquipmentPacket;
use softmine\network\protocol\RemoveBlockPacket;
use softmine\network\protocol\RemoveEntityPacket;
use softmine\network\protocol\RemovePlayerPacket;
use softmine\network\protocol\RespawnPacket;
use softmine\network\protocol\SetDifficultyPacket;
use softmine\network\protocol\SetEntityDataPacket;
use softmine\network\protocol\SetEntityMotionPacket;
use softmine\network\protocol\SetHealthPacket;
use softmine\network\protocol\SetSpawnPositionPacket;
use softmine\network\protocol\SetTimePacket;
use softmine\network\protocol\StartGamePacket;
use softmine\network\protocol\TakeItemEntityPacket;
use softmine\network\protocol\BlockEventPacket;
use softmine\network\protocol\UpdateBlockPacket;
use softmine\network\protocol\UseItemPacket;
use softmine\network\protocol\SetPlayerGameTypePacket;
use softmine\network\protocol\PlayerListPacket;
use softmine\Player;
use softmine\Server;
use softmine\utils\Binary;
use softmine\utils\MainLogger;

class Network{

	public static $BATCH_THRESHOLD = 512;

	/** @var \SplFixedArray */
	private $packetPool;

	/** @var Server */
	private $server;

	/** @var SourceInterface[] */
	private $interfaces = [];

	/** @var AdvancedSourceInterface[] */
	private $advancedInterfaces = [];

	private $upload = 0;
	private $download = 0;

	private $name;

	public function __construct(Server $server){

		$this->registerPackets();

		$this->server = $server;

	}

	public function addStatistics($upload, $download){
		$this->upload += $upload;
		$this->download += $download;
	}

	public function getUpload(){
		return $this->upload;
	}

	public function getDownload(){
		return $this->download;
	}

	public function resetStatistics(){
		$this->upload = 0;
		$this->download = 0;
	}

	/**
	 * @return SourceInterface[]
	 */
	public function getInterfaces(){
		return $this->interfaces;
	}

	public function processInterfaces(){
		foreach($this->interfaces as $interface){
			try {
				$interface->process();
			}catch(\Throwable $e){
				$logger = $this->server->getLogger();
				if(\softmine\DEBUG > 1){
					$logger->logException($e);
				}

				$interface->emergencyShutdown();
				$this->unregisterInterface($interface);
				$logger->critical($this->server->getLanguage()->translateString("softmine.server.networkError", [get_class($interface), $e->getMessage()]));
			}
		}
	}

	/**
	 * @param SourceInterface $interface
	 */
	public function registerInterface(SourceInterface $interface){
		$this->interfaces[$hash = spl_object_hash($interface)] = $interface;
		if($interface instanceof AdvancedSourceInterface){
			$this->advancedInterfaces[$hash] = $interface;
			$interface->setNetwork($this);
		}
		$interface->setName($this->name);
	}

	/**
	 * @param SourceInterface $interface
	 */
	public function unregisterInterface(SourceInterface $interface){
		unset($this->interfaces[$hash = spl_object_hash($interface)],
			$this->advancedInterfaces[$hash]);
	}

	/**
	 * Sets the server name shown on each interface Query
	 *
	 * @param string $name
	 */
	public function setName($name){
		$this->name = (string) $name;
		foreach($this->interfaces as $interface){
			$interface->setName($this->name);
		}
	}

	public function getName(){
		return $this->name;
	}

	public function updateName(){
		foreach($this->interfaces as $interface){
			$interface->setName($this->name);
		}
	}

	/**
	 * @param int        $id 0-255
	 * @param DataPacket $class
	 */
	public function registerPacket($id, $class){
		$this->packetPool[$id] = new $class;
	}

	public function getServer(){
		return $this->server;
	}

	public function processBatch(BatchPacket $packet, Player $p){
		$str = zlib_decode($packet->payload, 1024 * 1024 * 64); //Max 64MB
		$len = strlen($str);
		$offset = 0;
		try{
			while($offset < $len){
				$pkLen = Binary::readInt(substr($str, $offset, 4));
				$offset += 4;

				$buf = substr($str, $offset, $pkLen);
				$offset += $pkLen;

				if(($pk = $this->getPacket(ord($buf{1}))) !== null){ // #blameshoghi
					if($pk::NETWORK_ID === Info::BATCH_PACKET){
						throw new \InvalidStateException("Invalid BatchPacket inside BatchPacket");
					}

					$pk->setBuffer($buf, 2); // #blameshoghi

					$pk->decode();
					$p->handleDataPacket($pk);

					if($pk->getOffset() <= 0){
						return;
					}
				}
			}
		}catch(\Throwable $e){
			if(\softmine\DEBUG > 1){
				$logger = $this->server->getLogger();
				$logger->debug("BatchPacket " . " 0x" . bin2hex($packet->payload));
				$logger->logException($e);
			}
		}
	}

	/**
	 * @param $id
	 *
	 * @return DataPacket
	 */
	public function getPacket($id){
		/** @var DataPacket $class */
		$class = $this->packetPool[$id];
		if($class !== null){
			return clone $class;
		}
		return null;
	}


	/**
	 * @param string $address
	 * @param int    $port
	 * @param string $payload
	 */
	public function sendPacket($address, $port, $payload){
		foreach($this->advancedInterfaces as $interface){
			$interface->sendRawPacket($address, $port, $payload);
		}
	}

	/**
	 * Blocks an IP address from the main interface. Setting timeout to -1 will block it forever
	 *
	 * @param string $address
	 * @param int    $timeout
	 */
	public function blockAddress($address, $timeout = 300){
		foreach($this->advancedInterfaces as $interface){
			$interface->blockAddress($address, $timeout);
		}
	}

	private function registerPackets(){
		$this->packetPool = new \SplFixedArray(256);

		$this->registerPacket(ProtocolInfo::LOGIN_PACKET, LoginPacket::class);
		$this->registerPacket(ProtocolInfo::PLAY_STATUS_PACKET, PlayStatusPacket::class);
		$this->registerPacket(ProtocolInfo::DISCONNECT_PACKET, DisconnectPacket::class);
		$this->registerPacket(ProtocolInfo::BATCH_PACKET, BatchPacket::class);
		$this->registerPacket(ProtocolInfo::TEXT_PACKET, TextPacket::class);
		$this->registerPacket(ProtocolInfo::SET_TIME_PACKET, SetTimePacket::class);
		$this->registerPacket(ProtocolInfo::START_GAME_PACKET, StartGamePacket::class);
		$this->registerPacket(ProtocolInfo::ADD_PLAYER_PACKET, AddPlayerPacket::class);
		$this->registerPacket(ProtocolInfo::REMOVE_PLAYER_PACKET, RemovePlayerPacket::class);
		$this->registerPacket(ProtocolInfo::ADD_ENTITY_PACKET, AddEntityPacket::class);
		$this->registerPacket(ProtocolInfo::REMOVE_ENTITY_PACKET, RemoveEntityPacket::class);
		$this->registerPacket(ProtocolInfo::ADD_ITEM_ENTITY_PACKET, AddItemEntityPacket::class);
		$this->registerPacket(ProtocolInfo::TAKE_ITEM_ENTITY_PACKET, TakeItemEntityPacket::class);
		$this->registerPacket(ProtocolInfo::MOVE_ENTITY_PACKET, MoveEntityPacket::class);
		$this->registerPacket(ProtocolInfo::MOVE_PLAYER_PACKET, MovePlayerPacket::class);
		$this->registerPacket(ProtocolInfo::REMOVE_BLOCK_PACKET, RemoveBlockPacket::class);
		$this->registerPacket(ProtocolInfo::UPDATE_BLOCK_PACKET, UpdateBlockPacket::class);
		$this->registerPacket(ProtocolInfo::ADD_PAINTING_PACKET, AddPaintingPacket::class);
		$this->registerPacket(ProtocolInfo::EXPLODE_PACKET, ExplodePacket::class);
		$this->registerPacket(ProtocolInfo::LEVEL_EVENT_PACKET, LevelEventPacket::class);
		$this->registerPacket(ProtocolInfo::BLOCK_EVENT_PACKET, BlockEventPacket::class);
		$this->registerPacket(ProtocolInfo::ENTITY_EVENT_PACKET, EntityEventPacket::class);
		$this->registerPacket(ProtocolInfo::MOB_EQUIPMENT_PACKET, MobEquipmentPacket::class);
		$this->registerPacket(ProtocolInfo::MOB_ARMOR_EQUIPMENT_PACKET, MobArmorEquipmentPacket::class);
		$this->registerPacket(ProtocolInfo::INTERACT_PACKET, InteractPacket::class);
		$this->registerPacket(ProtocolInfo::USE_ITEM_PACKET, UseItemPacket::class);
		$this->registerPacket(ProtocolInfo::PLAYER_ACTION_PACKET, PlayerActionPacket::class);
		$this->registerPacket(ProtocolInfo::HURT_ARMOR_PACKET, HurtArmorPacket::class);
		$this->registerPacket(ProtocolInfo::SET_ENTITY_DATA_PACKET, SetEntityDataPacket::class);
		$this->registerPacket(ProtocolInfo::SET_ENTITY_MOTION_PACKET, SetEntityMotionPacket::class);
		$this->registerPacket(ProtocolInfo::SET_ENTITY_LINK_PACKET, SetEntityLinkPacket::class);
		$this->registerPacket(ProtocolInfo::SET_HEALTH_PACKET, SetHealthPacket::class);
		$this->registerPacket(ProtocolInfo::SET_SPAWN_POSITION_PACKET, SetSpawnPositionPacket::class);
		$this->registerPacket(ProtocolInfo::ANIMATE_PACKET, AnimatePacket::class);
		$this->registerPacket(ProtocolInfo::RESPAWN_PACKET, RespawnPacket::class);
		$this->registerPacket(ProtocolInfo::DROP_ITEM_PACKET, DropItemPacket::class);
		$this->registerPacket(ProtocolInfo::CONTAINER_OPEN_PACKET, ContainerOpenPacket::class);
		$this->registerPacket(ProtocolInfo::CONTAINER_CLOSE_PACKET, ContainerClosePacket::class);
		$this->registerPacket(ProtocolInfo::CONTAINER_SET_SLOT_PACKET, ContainerSetSlotPacket::class);
		$this->registerPacket(ProtocolInfo::CONTAINER_SET_DATA_PACKET, ContainerSetDataPacket::class);
		$this->registerPacket(ProtocolInfo::CONTAINER_SET_CONTENT_PACKET, ContainerSetContentPacket::class);
		$this->registerPacket(ProtocolInfo::CRAFTING_DATA_PACKET, CraftingDataPacket::class);
		$this->registerPacket(ProtocolInfo::CRAFTING_EVENT_PACKET, CraftingEventPacket::class);
		$this->registerPacket(ProtocolInfo::ADVENTURE_SETTINGS_PACKET, AdventureSettingsPacket::class);
		$this->registerPacket(ProtocolInfo::BLOCK_ENTITY_DATA_PACKET, BlockEntityDataPacket::class);
		$this->registerPacket(ProtocolInfo::FULL_CHUNK_DATA_PACKET, FullChunkDataPacket::class);
		$this->registerPacket(ProtocolInfo::SET_DIFFICULTY_PACKET, SetDifficultyPacket::class);
		$this->registerPacket(ProtocolInfo::SET_PLAYER_GAMETYPE_PACKET, SetPlayerGameTypePacket::class);
		$this->registerPacket(ProtocolInfo::PLAYER_LIST_PACKET, PlayerListPacket::class);
	}
}
