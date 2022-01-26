<?php

namespace TheArdent\Drivers\Viber;

use BotMan\BotMan\Interfaces\DriverEventInterface;
use BotMan\BotMan\Users\User;
use Illuminate\Support\Collection;
use BotMan\BotMan\Drivers\HttpDriver;
use BotMan\BotMan\Messages\Incoming\Answer;
use BotMan\BotMan\Messages\Outgoing\Question;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ParameterBag;
use BotMan\BotMan\Messages\Incoming\IncomingMessage;
use BotMan\BotMan\Messages\Attachments\Audio;
use BotMan\BotMan\Messages\Attachments\File;
use BotMan\BotMan\Messages\Attachments\Image;
use BotMan\BotMan\Messages\Attachments\Location;
use BotMan\BotMan\Messages\Attachments\Video;
use BotMan\BotMan\Messages\Outgoing\OutgoingMessage;
use TheArdent\Drivers\Viber\Attachments\Contact;
use TheArdent\Drivers\Viber\Events\MessageDelivered;
use TheArdent\Drivers\Viber\Events\MessageFailed;
use TheArdent\Drivers\Viber\Events\MessageSeen;
use TheArdent\Drivers\Viber\Events\MessageStarted;
use TheArdent\Drivers\Viber\Events\UserSubscribed;
use TheArdent\Drivers\Viber\Events\UserUnsubscribed;
use TheArdent\Drivers\Viber\Events\Webhook;
use TheArdent\Drivers\Viber\Extensions\ContactTemplate;
use TheArdent\Drivers\Viber\Extensions\FileTemplate;
use TheArdent\Drivers\Viber\Extensions\KeyboardTemplate;
use TheArdent\Drivers\Viber\Extensions\LinkTemplate;
use TheArdent\Drivers\Viber\Extensions\LocationTemplate;
use TheArdent\Drivers\Viber\Extensions\PictureTemplate;
use TheArdent\Drivers\Viber\Extensions\VideoTemplate;

class ViberDriver extends HttpDriver
{
	const DRIVER_NAME = 'Viber';

	const API_ENDPOINT = 'https://chatapi.viber.com/pa/';

	/** @var string */
	protected $signature;

	/** @var  DriverEventInterface */
	protected $driverEvent;

	/** @var string|null */
	private $botId;

	/** @var  array|object */
	private $bot;

	/**
	 * @param Request $request
	 */
	public function buildPayload(Request $request)
	{

		$this->payload = new ParameterBag((array) json_decode($request->getContent(), true));
		$this->content = $request->getContent();
		$this->event = Collection::make($this->payload->get('event'), []);
		$this->signature = $request->headers->get('X-Viber-Content-Signature', '');
		$this->config = Collection::make($this->config->get('viber'), []);
	}

	/**
	 * @return array
	 */
	protected function getHeaders()
	{
		return [
			'Accept:application/json',
			'Content-Type:application/json',
			'X-Viber-Auth-Token: '.$this->config->get('token'),
		];
	}

	/**
	 * Determine if the request is for this driver.
	 *
	 * @return bool
	 */
	public function matchesRequest()
	{
		return $this->payload->get('event') && $this->payload->get('message_token');
	}

	/**
	 * @return bool|DriverEventInterface
	 */
	public function hasMatchingEvent()
	{
		$event = $this->getEventFromEventData($this->payload->all());
		if ($event) {
			$this->driverEvent = $event;
			return $this->driverEvent;
		}
		return false;
	}

	/**
	 * @param array $eventData
	 *
	 * @return bool|DriverEventInterface
	 */
	public function getEventFromEventData(array $eventData)
	{
		switch ($this->event->first()) {
			case 'delivered':
				return new MessageDelivered($eventData);
				break;
			case 'failed':
				return new MessageFailed($eventData);
				break;
			case 'subscribed':
				return new UserSubscribed($eventData);
				break;
			case 'conversation_started':
				return new MessageStarted($eventData);
				break;
			case 'unsubscribed':
				return new UserUnsubscribed($eventData);
				break;
			case 'seen':
				return new MessageSeen($eventData);
				break;
			case 'webhook':
				return new Webhook($eventData);
				break;
			default:
				return false;
				break;
		}
	}

	/**
	 * @param  \BotMan\BotMan\Messages\Incoming\IncomingMessage $message
	 * @return Answer
	 */
	public function getConversationAnswer(IncomingMessage $message)
	{
		return Answer::create($message->getText())->setMessage($message);
	}

	/**
	 * Retrieve the chat message.
	 *
	 * @return array
	 */
	public function getMessages()
	{
		$user = $this->payload->get('sender') ? $this->payload->get('sender')['id'] : ($this->payload->get('user')['id'] ?? null);
		if ($user === null) {
			return [];
		}

		if (isset($this->payload->get('message')['type'])) {
			if ($this->payload->get('message')['type'] == 'text') {
				$message = new IncomingMessage($this->payload->get('message')['text'], $user, $this->getBotId(), $this->payload);
			} elseif ($this->payload->get('message')['type'] == 'location') {
				$message = new IncomingMessage(Location::PATTERN, $user, $this->getBotId(), $this->payload);
				$message->setLocation(
					new Location(
						$this->payload->get('message')['location']['lat'],
						$this->payload->get('message')['location']['lon'],
						$this->payload->get('message')['location']
					)
				);
			} elseif ($this->payload->get('message')['type'] == 'picture') {
				$message = new IncomingMessage(Image::PATTERN, $user, $this->getBotId(), $this->payload);
				$message->setImages([
					(new Image(
						$this->payload->get('message')['media'],
						$this->payload->get('message')
					))
						->addExtras('size', $this->payload->get('message')['size'] ?? '')
						->addExtras('file_name', $this->payload->get('message')['file_name'] ?? '')
						->addExtras('thumbnail', $this->payload->get('message')['thumbnail'] ?? '')
				]);
			} elseif ($this->payload->get('message')['type'] == 'contact') {
				$message = new IncomingMessage(Contact::PATTERN, $user, $this->getBotId(), $this->payload);
				$message->addExtras('contact',
					new Contact(
						$this->payload->get('message')['contact']['phone_number'],
						$this->payload->get('message')['contact']['name'] ?? ''
					)
				);
			}
		}

		if (!isset($message)) {
			if (isset($this->payload->get('message')['text'])) {
				$message = new IncomingMessage($this->payload->get('message')['text'], $user, $this->getBotId(), $this->payload);
			} else {
				$message = new IncomingMessage('', $user, $this->getBotId(), $this->payload);
			}
		}
		return [$message];
	}

	/**
	 * Convert a Question object
	 *
	 * @param Question $question
	 * @return array
	 */
	private function convertQuestion(Question $question)
	{
		$actions = $question->getActions();
		if (count($actions) > 0 ) {
			$keyboard = new KeyboardTemplate($question->getText());
			foreach($actions as $action) {
				$keyboard->addButton($action['text'], 'reply', $action['value'] ?? $action['text']);
			}
			return $keyboard->jsonSerialize();
		}

		return [
			'text' => $question->getText(),
			'type' => 'text'
		];
	}

	/**
	 * @param string|Question|IncomingMessage $message
	 * @param \BotMan\BotMan\Messages\Incoming\IncomingMessage $matchingMessage
	 * @param array $additionalParameters
	 * @return array
	 */
	public function buildServicePayload($message, $matchingMessage, $additionalParameters = [])
	{
		$parameters = array_merge_recursive([
			'receiver' => $matchingMessage->getSender(),
			'min_api_version' => 8
		], $additionalParameters);

		if ($message instanceof Question) {
			$parameters = array_merge_recursive($this->convertQuestion($message), $parameters);
		} elseif ($message instanceof OutgoingMessage) {
			$attachment = $message->getAttachment();
			if (! is_null($attachment)) {
				$attachmentType = strtolower(basename(str_replace('\\', '/', get_class($attachment))));
				if ($attachmentType == 'image' && $attachment instanceof Image) {
					$template = new PictureTemplate($attachment->getUrl(), $attachment->getTitle());
				} elseif ($attachmentType == 'video' && $attachment instanceof Video) {
					$template = new VideoTemplate($attachment->getUrl());
				} elseif ($attachmentType == 'audio' && $attachment instanceof Audio) {
					$fileName = $parameters['file_name'] ?? uniqid();
					$template = new FileTemplate($attachment->getUrl(), $fileName.(($ext=pathinfo($attachment->getUrl(), PATHINFO_EXTENSION))?'.'.$ext:''));
				} elseif ($attachmentType == 'file' && $attachment instanceof File) {
					$fileName = $parameters['file_name'] ?? uniqid();
					$template = new FileTemplate($attachment->getUrl(), $fileName.(($ext=pathinfo($attachment->getUrl(), PATHINFO_EXTENSION))?'.'.$ext:''));
				} elseif ($attachmentType == 'location' && $attachment instanceof Location) {
					$template = new LocationTemplate($attachment->getLatitude(), $attachment->getLongitude());
				}

				if (isset($template)) {
					$parameters = array_merge($template->jsonSerialize(), $parameters);
				}
			} else {
				$parameters['text'] = $message->getText();
				$parameters['type'] = 'text';
			}
		} elseif ($message instanceof \JsonSerializable) {
			$parameters = array_merge($message->jsonSerialize(),$parameters);
		} else {
			$parameters['text'] = $message->getText();
			$parameters['type'] = 'text';
		}

		return $parameters;
	}

	/**
	 * @param mixed $payload
	 * @return Response
	 */
	public function sendPayload($payload)
	{
		return $this->http->post(self::API_ENDPOINT.'send_message', [], $payload, $this->getHeaders(), true);
	}

	/**
	 * @return bool
	 */
	public function isConfigured()
	{
		return ! is_null($this->config->get('token'));
	}

	/**
	 * Retrieve User information.
	 * @param \BotMan\BotMan\Messages\Incoming\IncomingMessage $matchingMessage
	 * @return User
	 */
	public function getUser(IncomingMessage $matchingMessage)
	{
		$personId = $matchingMessage->getSender();
		$response = $this->http->post(self::API_ENDPOINT.'get_user_details',[],['id' => $personId], $this->getHeaders(), true);
		$userInfo = Collection::make(json_decode($response->getContent(), true)['user']);

		return new User($userInfo->get('id'), $userInfo->get('name'), null, $userInfo->get('name'), $userInfo->toArray());
	}


	/**
	 * Low-level method to perform driver specific API requests.
	 *
	 * @param string          $endpoint
	 * @param array           $parameters
	 * @param IncomingMessage $matchingMessage
	 *
	 * @return Response
	 */
	public function sendRequest($endpoint, array $parameters, IncomingMessage $matchingMessage)
	{
		return $this->http->post(self::API_ENDPOINT.$endpoint, [], $parameters, $this->getHeaders());
	}

	/**
	 * Returns the chatbot ID.
	 * @return string
	 */
	private function getBotId()
	{
		if (is_null($this->bot)) {
			$response = $this->http->post(self::API_ENDPOINT.'get_account_info', [], [], $this->getHeaders());
			$bot = json_decode($response->getContent());
			$this->bot = $bot;
			$this->botId = $bot->id;
		}

		return $this->botId;
	}
}
