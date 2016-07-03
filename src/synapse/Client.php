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

use synapse\event\client\ClientAuthEvent;
use synapse\event\client\ClientConnectEvent;
use synapse\event\client\ClientDisconnectEvent;
use synapse\event\client\ClientRecvPacketEvent;
use synapse\event\client\ClientSendPacketEvent;
use synapse\network\protocol\mcpe\GenericPacket;
use synapse\network\protocol\spp\ConnectPacket;
use synapse\network\protocol\spp\DataPacket;
use synapse\network\protocol\spp\DisconnectPacket;
use synapse\network\protocol\spp\Info;
use synapse\network\protocol\spp\InformationPacket;
use synapse\network\protocol\spp\RedirectPacket;
use synapse\network\protocol\spp\TransferPacket;
use synapse\network\SynapseInterface;

class Client{
	/** @var Server */
	private $server;
	/** @var SynapseInterface */
	private $interface;
	private $ip;
	private $port;
	/** @var Player[] */
	private $players = [];
	private $verified = false;
	private $isMainServer = false;
	private $maxPlayers;
	private $lastUpdate;
	private $description;

	public function __construct(SynapseInterface $interface, $ip, int $port){
		$this->server = $interface->getServer();
		$this->interface = $interface;
		$this->ip = $ip;
		$this->port = $port;
		$this->lastUpdate = microtime(true);

		$this->server->getPluginManager()->callEvent(new ClientConnectEvent($this));
	}

	public function isMainServer() : bool{
		return $this->isMainServer;
	}

	public function getMaxPlayers() : int{
		return $this->maxPlayers;
	}

	public function getHash() : string{
		return $this->ip . ':' . $this->port;
	}

	public function getDescription() : string {
		return $this->description;
	}

	public function setDescription(string $description){
		$this->description = $description;
	}

	public function onUpdate($currentTick){
		if((microtime(true) - $this->lastUpdate) >= 30){//30 seconds timeout
			$this->close("timeout");
		}
	}

	public function handleDataPacket(DataPacket $packet){
		/*$this->server->getPluginManager()->callEvent($ev = new ClientRecvPacketEvent($this, $packet));
		if($ev->isCancelled()){
			return;
		}*/
		switch($packet::NETWORK_ID){
			case Info::HEARTBEAT_PACKET:
				if(!$this->isVerified()){
					$this->server->getLogger()->error("Client {$this->getIp()}:{$this->getPort()} is not verified");
					return;
				}
				$this->lastUpdate = microtime(true);
				$this->server->getLogger()->notice("Received Heartbeat Packet from {$this->getIp()}:{$this->getPort()}");

				$pk = new InformationPacket();
				$pk->type = InformationPacket::TYPE_CLIENT_DATA;
				$pk->message = $this->server->getClientData();
				$this->sendDataPacket($pk);

				break;
			case Info::CONNECT_PACKET:
				/** @var ConnectPacket $packet */
				if($packet->protocol != Info::CURRENT_PROTOCOL){
					$this->close("Wrong protocol! Require SPP version: " . Info::CURRENT_PROTOCOL, true, DisconnectPacket::TYPE_WRONG_PROTOCOL);
					return;
				}
				$pk = new InformationPacket();
				$pk->type = InformationPacket::TYPE_LOGIN;
				if($this->server->comparePassword(base64_decode($packet->encodedPassword))){
					$this->setVerified();
					$pk->message = InformationPacket::INFO_LOGIN_SUCCESS;
					$this->isMainServer = $packet->isMainServer;
					$this->description = $packet->description;
					$this->maxPlayers = $packet->maxPlayers;
					$this->server->addClient($this);
					$this->server->getLogger()->notice("Client {$this->getIp()}:{$this->getPort()} has connected successfully");
					$this->server->getLogger()->notice("mainServer: " . ($this->isMainServer ? "true" : "false"));
					$this->server->getLogger()->notice("description: $this->description");
					$this->server->getLogger()->notice("maxPlayers: $this->maxPlayers");
					$this->server->updateClientData();
					$this->sendDataPacket($pk);
				}else{
					$pk->message = InformationPacket::INFO_LOGIN_FAILED;
					$this->server->getLogger()->emergency("Client {$this->getIp()}:{$this->getPort()} tried to connect with wrong password!");
					$this->sendDataPacket($pk);
					$this->close("Auth failed!");
				}
				$this->server->getPluginManager()->callEvent(new ClientAuthEvent($this, $packet->encodedPassword));
				break;
			case Info::DISCONNECT_PACKET:
				/** @var DisconnectPacket $packet */
				$this->close($packet->message, false);
				break;
			case Info::REDIRECT_PACKET:
				/** @var RedirectPacket $packet */
				if(isset($this->players[$uuid = $packet->uuid->toBinary()])){
					$pk = new GenericPacket();
					$pk->buffer = $packet->mcpeBuffer;
					$this->players[$uuid]->sendDataPacket($pk, $packet->direct);
				}else{
					$this->server->getLogger()->error("Error RedirectPacket");
				}
				break;
			case Info::TRANSFER_PACKET:
				/** @var TransferPacket $pk */
				$clients = $this->server->getClients();
				if(isset($this->players[$uuid = $packet->uuid->toBinary()]) and isset($clients[$packet->clientHash])){
					$this->players[$uuid]->transfer($clients[$packet->clientHash], true);
				}
				break;
			default:
				$this->server->getLogger()->error("Client {$this->getIp()}:{$this->getPort()} send an unknown packet " . $packet::NETWORK_ID);
		}
	}

	public function sendDataPacket(DataPacket $pk){
		$this->interface->putPacket($this, $pk);
		/*$this->server->getPluginManager()->callEvent($ev = new ClientSendPacketEvent($this, $pk));
		if(!$ev->isCancelled()){
			$this->interface->putPacket($this, $pk);
		}*/
	}

	public function getIp(){
		return $this->ip;
	}

	public function getPort() : int{
		return $this->port;
	}

	public function isVerified() : bool{
		return $this->verified;
	}

	public function setVerified(){
		$this->verified = true;
	}

	public function getPlayers(){
		return $this->players;
	}

	public function addPlayer(Player $player){
		$this->players[$player->getUniqueId()->toBinary()] = $player;
	}

	public function removePlayer(Player $player){
		unset($this->players[$player->getUniqueId()->toBinary()]);
	}

	public function closeAllPlayers(){
		foreach($this->players as $player){
			$player->close("Server closed!");
		}
	}

	public function close(string $reason = "Generic reason", bool $needPk = true, int $type = DisconnectPacket::TYPE_GENERIC){
		$this->server->getPluginManager()->callEvent($ev = new ClientDisconnectEvent($this, $reason, $type));
		$reason = $ev->getReason();
		$this->server->getLogger()->info("Client $this->ip:$this->port has disconnected due to $reason");
		if($needPk){
			$pk = new DisconnectPacket();
			$pk->type = $type;
			$pk->message = $reason;
			$this->sendDataPacket($pk);
		}
		$this->closeAllPlayers();
		$this->interface->removeClient($this);
		$this->server->removeClient($this);
	}
}
