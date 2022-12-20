<?php

namespace rohsyl\Salto;

use rohsyl\Salto\Exceptions\NakException;
use rohsyl\Salto\Exceptions\SaltoErrorException;
use rohsyl\Salto\Exceptions\WrongChecksumException;
use rohsyl\Salto\Messages\Message;
use rohsyl\Salto\Response\Response;

/**
 *
 */
class SaltoClient
{
    const STX = 0x02; // Start of text, indicates the start of a message
    const ETX = 0x03; // End of text, indicates the end of a message
    const ENQ = 0x05; // Enquiry about the PC interface being ready to receive a new message
    const ACK = 0x06; // Positive acknowledgement to a PMS message or enquiry
    const NAK = 0x15; // Negative acknowledgement to a PMS message or enquiry
    const LRC_SKIP = 0x0D; // Skip LRC, indicates to skip LRC check.

    const SEPARATOR = 0xB3; // Field separator

    const DATE_FORMAT = 'hhmmDDMMYY';

    private $endpoint;
    private $port;
    private $lrc_skip = false;

    /**
     * @var Socket
     */
    private $socket;

    public function __construct(string $endpoint, int $port)
    {
        $this->endpoint = $endpoint;
        $this->port = $port;
    }

    public function skipLrc($lrc_skip = true) {
        $this->lrc_skip = $lrc_skip;

        return $this;
    }

    public function openSocketConnection() {
        $this->socket = Salto::getSocket($this);
        $this->socket->open();
    }

    public function isReady() {
        return $this->sendRequest([self::ENQ])->isAck();
    }

    public function sendRequest(array $frame) : Response {

        $waitAnswer = !$this->isEnq($frame);

        // convert string array to binary string
        $frame = $this->stringArrayToBinaryString($frame);
        echo $frame . "\n";

        echo 'Frame sent : ' . $this->binaryStringToHexaString($frame) . "\n";

        $result = $this->socket->write($frame);

        return $this->readResponse($waitAnswer);
    }

    private function stringArrayToBinaryString(array $strings) {

        $binaryString = '';

        foreach ($strings as $string) {
            if(is_string($string)) {
                $binaryString .= $this->stringToBinary($string);
            }
            else {
                $binaryString.= pack('C*', $string);
            }
        }
        return $binaryString;
    }

    private function stringToBinary(string $string) {
        $binary = '';
        foreach(str_split($string) as $char) {

            $binary .= pack('C*', ord($char));
        }
        return $binary;
    }

    private function binaryToDecimal($byte) {
        return intval(join(unpack('C*', $byte)));
    }

    private function decimalToHexaString($frame) {
        $string = '';
        foreach($frame as $dec) {
            $string .= '0x' . str_pad(dechex($dec), 2, '0', STR_PAD_LEFT) . ' ';
        }
        return $string;
    }

    private function binaryStringToHexaString($frame) {
        $decArray = unpack('C*', $frame);
        $string = '';
        foreach($decArray as $dec) {
            $string .= '0x' . str_pad(dechex($dec), 2, '0', STR_PAD_LEFT) . ' ';
        }
        return $string;
    }

    public function isEnq($frame) {
        return $frame[0] ?? null === self::ENQ;
    }

    public function readResponse($waitAnswer = true) {

        $isFrame = false;

        $isBody = false;
        $body = null;
        $bodyFieldIndex = null;

        $isChecksum = false;
        $checksum = null;
        $response = null;
        do {
            echo "read...\n";
            $byte = $this->socket->readByte();
            $byte = $this->binaryToDecimal($byte);
            echo "Byte read : " . $this->decimalToHexaString([$byte]) . "\n";


            if($byte == SaltoClient::ACK) {
                echo "ack...\n";
                if(!$waitAnswer) {
                    return Response::Ack();
                }
                // wait for the next byte stx
                $isFrame = false;
                continue;
            }
            if($byte == SaltoClient::NAK) {
                echo "nak...\n";
                // request wont be processed
                $response = Response::Nak();
                break;
            }

            // are we already processing a frame ?
            if(!$isFrame) {
                // wait until we get a stx that means it's the start of a frame
                if ($byte === SaltoClient::STX) {
                    $isFrame = true;
                }
            }
            // are we already processing the body of the frame ?
            else if (!$isBody) {
                // the body is composed of many fields separated by the separator.
                // the length of the body can vary.

                // if we get the first separator after the stx then it means it's
                // the begining of the message body.
                // we can init the body array that will contain every fields.
                // and init the current field index to 0
                if($byte === SaltoClient::SEPARATOR && !isset($body)) {
                    $body = [];
                    $bodyFieldIndex = 0;
                }
                // if we get another separator it's that we have retrived every bytes for the current field
                // and that we can start to retrive the next field bytes.
                // so we increment the field index by 1
                else if($byte === SaltoClient::SEPARATOR) {
                    $bodyFieldIndex++;
                }
                // if we get the etx it means that we got all fields of the message body
                else if($byte === SaltoClient::ETX) {
                    $isBody = true;
                }
                // otherwise retreive bytes for the current field index.
                else {
                    if(!isset($body[$bodyFieldIndex])) {
                        $body[$bodyFieldIndex] = [];
                    }
                    $body[$bodyFieldIndex][] = $byte;
                }

            }
            else if (!$isChecksum) {
                $checksum = $byte;

                $response = new Response($body, $checksum);
                break;
            }

        } while (true);


        if($response->isNak()) {
            throw new NakException($response);
        }

        if(!$response->check()) {
            throw new WrongChecksumException($response);
        }

        if($response->isError()) {
            throw new SaltoErrorException($response);
        }


        return $response;
    }

    public function sendMessage(Message $message) {

        $message->skipLrc($this->lrc_skip);

        $response = $this->sendRequest($message->getFrame());

        $response->setRequest($message);

        return $response;
    }


    /**
     * @return string
     */
    public function getEndpoint(): string
    {
        return $this->endpoint;
    }

    /**
     * @return int
     */
    public function getPort(): int
    {
        return $this->port;
    }

    /**
     * Compute the LRC checksum for the given array of bytes
     * @param array $bytearray
     * @return int
     */
    public static function computeLrc(array $bytearray) {
        $lrc = 0x00;
        foreach ($bytearray as $char) {
            $lrc ^= ord($char);
        }
        return $lrc;
    }

    private static $_errors = [
        'ES' => 'Syntax error. The received message from the PMS is not correct (unknown command, nonsense parameters, prohibited characters, etc.)',
        'NC' => 'No communication. The specified encoder does not answer (encoder is switched off, disconnected from the PC interface, etc.)',
        'NF' => 'No files. Database file in the PC interface is damaged, corrupted or not found.',
        'OV' => 'Overflow. The encoder is still busy executing a previous task and cannot accept a new one.',
        'EP' => 'Card error. Card not found or wrongly inserted in the encoder.',
        'EF' => 'Format error. The card has been encoded by another system or may be damaged.',
        'TD' => 'Unknown room. This error occurs when trying to encode a card for a non-existing room.',
        'ED' => 'Timeout error. The encoder has been waiting too long for a card to be inserted. The operation is cancelled.',
        'EA' => 'This error occurs when the PC interface cannot execute the ‘CC’ command (encode copies of a guest card) because the room is checked out.',
        'OS' => 'This error occurs when the requested room is out of service.',
        'EO' => 'The requested guest card is being encoded by another station.',
        'EG' => 'General error. When the resulting error is none of the above described, the PC interface returns an ‘EG’ followed by an encoder number (or phone number depending on the original request) and an error description.',
    ];

    public static function getErrors() : array {
        return array_keys(self::$_errors);
    }

    public static function getErrorDescription($error) {
        return self::$_errors[$error] ?? null;
    }
}
