<?php

/*
 *
 *  _____   _____   __   _   _   _____  __    __  _____
 * /  ___| | ____| |  \ | | | | /  ___/ \ \  / / /  ___/
 * | |     | |__   |   \| | | | | |___   \ \/ /  | |___
 * | |  _  |  __|  | |\   | | | \___  \   \  /   \___  \
 * | |_| | | |___  | | \  | | |  ___| |   / /     ___| |
 * \_____/ |_____| |_|  \_| |_| /_____/  /_/     /_____/
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * @author iTX Technologies
 * @link https://itxtech.org
 *
 */

namespace synapse;

use synapse\network\protocol\mcpe\BatchPacket;
use synapse\network\protocol\mcpe\DataPacket;
use synapse\network\protocol\mcpe\DisconnectPacket;
use synapse\network\protocol\mcpe\Info;
use synapse\network\protocol\mcpe\PlayerListPacket;
use synapse\network\protocol\mcpe\PlayStatusPacket;
use synapse\network\protocol\spp\PlayerLoginPacket;
use synapse\network\protocol\spp\PlayerLogoutPacket;
use synapse\network\protocol\spp\RedirectPacket;
use synapse\network\SourceInterface;
use synapse\utils\UUID;
use synapse\utils\TextFormat;

class Player{
	/** @var DataPacket */
	private $cachedLoginPacket;
	private $name;
	private $ip;
	private $port;
	private $clientId;
	private $randomClientId;
	private $protocol;
	/** @var UUID */
	private $uuid;
	/** @var SourceInterface */
	private $interface;
	/** @var Client */
	private $client;
	/** @var Server */
	private $server;
	private $rawUUID;
	private $isFirstTimeLogin = true;
	private $lastUpdate;
	private $current_version = "0.15.x";

	public function __construct(SourceInterface $interface, $clientId, $ip, int $port){
		$this->interface = $interface;
		$this->clientId = $clientId;
		$this->ip = $ip;
		$this->port = $port;
		$this->name = "Unknown";
		$this->server = Server::getInstance();
		$this->lastUpdate = microtime(true);
	}

	public function getClientId(){
		return $this->randomClientId;
	}

	public function getRawUUID(){
		return $this->rawUUID;
	}

	public function getServer() : Server{
		return $this->server;
	}

	public function handleDataPacket(DataPacket $pk){
		$this->lastUpdate = microtime(true);
		$packet = new PlayStatusPacket();
		$packet->status = PlayStatusPacket::LOGIN_SUCCESS;
		$this->sendDataPacket($packet);
		switch($pk::NETWORK_ID){
			case Info::BATCH_PACKET:
				$packet = new PlayStatusPacket();
				$packet->status = PlayStatusPacket::LOGIN_SUCCESS;
				$this->sendDataPacket($packet);
				/** @var BatchPacket $pk */
				$this->getServer()->getNetwork()->processBatch($pk, $this);
				break;
			case Info::LOGIN_PACKET:
				$this->cachedLoginPacket = $pk->buffer;
				$this->name = $pk->username;
				$this->uuid = UUID::fromString($pk->clientUUID);
				$this->rawUUID = $this->uuid->toBinary();
				$this->randomClientId = $pk->clientId;
				$this->protocol = $pk->protocol;
				
				if(!in_array($packet->protocol, ProtocolInfo::ACCEPTED_PROTOCOLS)){
					if($packet->protocol < ProtocolInfo::CURRENT_PROTOCOL){
						$message = "Your MCPE version is outdated! Please update to ".$this->current_version."!";
						$pk = new PlayStatusPacket();
						$pk->status = PlayStatusPacket::LOGIN_FAILED_CLIENT;
						$this->directDataPacket($pk);
					}else{
						$message = "Server is outdated!";
						$pk = new PlayStatusPacket();
						$pk->status = PlayStatusPacket::LOGIN_FAILED_SERVER;
						$this->directDataPacket($pk);
					}
					$this->close("", $message, false);
					break;
				}

				$this->server->getLogger()->info($this->getServer()->getLanguage()->translateString("synapse.player.logIn", [
					TextFormat::AQUA . $this->name . TextFormat::WHITE,
					$this->ip,
					$this->port,
					TextFormat::GREEN . $this->randomClientId . TextFormat::WHITE,
				]));

				$c = $this->server->getMainClients();
				if(count($c) > 0){
					$this->transfer($c[array_rand($c)]);
				}else{
					$this->close("Synapse Server: " . TextFormat::RED . "No server online!");
				}
				break;
			default:
				$packet = new RedirectPacket();
				$packet->uuid = $this->uuid;
				$packet->direct = false;
				$packet->mcpeBuffer = $pk->buffer;
				$this->client->sendDataPacket($packet);
		}
	}

	public function getIp(){
		return $this->ip;
	}

	public function getPort() : int{
		return $this->port;
	}

	public function getUUID(){
		return $this->uuid;
	}

	public function getName() : string{
		return $this->name;
	}
	
	public function onUpdate($currentTick){
		if((microtime(true) - $this->lastUpdate) >= 5 * 60){//5 minutes timeout
			$this->close("timeout");
		}
	}

	public function removeAllPlayer(){
		$pk = new PlayerListPacket();
		$pk->type = PlayerListPacket::TYPE_REMOVE;
		foreach($this->client->getPlayers() as $p){
			$pk->entries[] = $p->getUUID();
		}
		$this->sendDataPacket($pk);
	}

	public function transfer(Client $client, bool $needDisconnect = false){
		if($this->client instanceof Client and $needDisconnect){
			$pk = new PlayerLogoutPacket();
			$pk->uuid = $this->uuid;
			$pk->reason = "Player has been transferred";
			$this->client->sendDataPacket($pk);

			$this->client->removePlayer($this);

			$this->removeAllPlayer();
		}
		$this->client = $client;
		$this->client->addPlayer($this);
		$pk = new PlayerLoginPacket();
		$pk->uuid = $this->uuid;
		$pk->address = $this->ip;
		$pk->port = $this->port;
		$pk->isFirstTime = $this->isFirstTimeLogin;
		$pk->cachedLoginPacket = $this->cachedLoginPacket;
		$this->client->sendDataPacket($pk);

		$this->isFirstTimeLogin = false;

		$this->server->getLogger()->info("{$this->name} has been transferred to {$this->client->getIp()}:{$this->client->getPort()}");
	}

	public function sendDataPacket(DataPacket $pk, $direct = false, $needACK = false){
		$this->interface->putPacket($this, $pk, $needACK, $direct);
	}

	public function close(string $reason = "Generic reason"){
		$pk = new DisconnectPacket();
		$pk->message = $reason;
		$this->sendDataPacket($pk, true);
		$this->interface->close($this, $reason);

		if($this->client instanceof Client){
			$pk = new PlayerLogoutPacket();
			$pk->uuid = $this->uuid;
			$pk->reason = $reason;
			$this->client->sendDataPacket($pk);
			$this->client->removePlayer($this);
		}
		
		$this->getServer()->removePlayer($this);
	}
}
