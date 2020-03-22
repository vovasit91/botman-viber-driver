<?php

namespace TheArdent\Drivers\Viber\Attachments;

use BotMan\BotMan\Messages\Attachments\Attachment;

class Contact extends Attachment
{
    /**
     * Pattern that messages use to identify contact.
     */
    const PATTERN = '%%%_CONTACT_%%%';

    /** @var string */
    protected $name;

    /** @var string */
    protected $phone;

    /**
     * Contact constructor.
     * @param string $phone
     * @param string $name
     * @param mixed $payload
     */
    public function __construct($phone, $name, $payload = null)
    {
        parent::__construct($payload);
        $this->phone = $phone;
        $this->name = $name;
    }

    /**
     * @param $phone
     * @param $name
     * @return Contact
     */
    public static function create($phone, $name)
    {
        return new self($phone, $name);
    }

    /**
     * @return string
     */
    public function getPhone()
    {
        return $this->phone;
    }

    /**
     * @return string
     */
    public function getName()
    {
        return $this->name;
    }

    /**
     * Get the instance as a web accessible array.
     * This will be used within the WebDriver.
     *
     * @return array
     */
    public function toWebDriver()
    {
        return [
            'type' => 'contact',
            'phone' => $this->phone,
            'name' => $this->name
        ];
    }
}
