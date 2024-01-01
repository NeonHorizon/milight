<?php
/*------------------------------------------------------------------------------
  MiLight Driver
  The MiLight driver library
--------------------------------------------------------------------------------
  Daniel Bull
  daniel@neonhorizon.co.uk
------------------------------------------------------------------------------*/
namespace milight;



class ibox
{

  // Settings
  const PASSWORD             = '0000';
  const SEND_TIMEOUT         = 0.3;
  const SESSION_TIMEOUT      = 30;
  const EXECUTE_RETRY_LOOPS  = 10;
  const EXECUTE_SESSION_TRY  = 5;
  const LINK_LOOPS           = 3;
  const MAX_RATE             = 0.03;
  const WAKEUP_TIME          = 0.05;

  // Communications
  const GET_SESSION          = '200000001602623AD5EDA301AE082D466141A7F6DCAFD3E600001E';
  const RECEIVE_SESSION      = '28000000110002';
  const SEND                 = '8000000011';
  const RECEIVE              = '8800000003';

  // Actions
  const COMMAND              = '31';
  const LINK                 = '3D';
  const UNLINK               = '3E';

  // RGBWW commands
  const RGBWW_LINK_UNLINK    = '080000';
  const RGBWW_COLOR          = '0801'; // Followed by 00 to FF
  const RGBWW_SATURATION     = '0802'; // Followed by 00 to 64
  const RGBWW_BRIGHTNESS     = '0803'; // Followed by 00 to 64
  const RGBWW_ON             = '080401';
  const RGBWW_OFF            = '080402';
  const RGBWW_FASTER         = '080403';
  const RGBWW_SLOWER         = '080404';
  const RGBWW_NIGHT          = '080405';
  const RGBWW_WHITE          = '0805'; // Followed by 00 to 64
  const RGBWW_MODE           = '0806'; // Followed by 00 to 09

  // iBox lamp commands
  const IBOX_LAMP_COLOR      = '0001'; // Followed by 00 to FF
  const IBOX_LAMP_BRIGHTNESS = '0002'; // Followed by 00 to 64
  const IBOX_LAMP_SLOWER     = '000301';
  const IBOX_LAMP_FASTER     = '000302';
  const IBOX_LAMP_ON         = '000303';
  const IBOX_LAMP_OFF        = '000304';
  const IBOX_LAMP_WHITE      = '000305';
  const IBOX_LAMP_MODE       = '0004'; // Followed by 00 to 09

  // Connection
  public  $ip_address        = NULL;
  public  $port              = 5987;
  private $socket            = NULL;
  private $session           = NULL;
  private $serial            = NULL;
  private $next_send         = 0;



  /*------------------------------------------------------------------------------
    Constructor
  ------------------------------------------------------------------------------*/
  public function __construct($ip_address = NULL, $port = NULL)
  {
    // Set the port
    if($port !== NULL)
      $this->port = $port;

    // Set the IP address
    if($ip_address === NULL)
      trigger_error('Please supply an IP address (and optionally a port) to new ibox($ip_address [, $port] );', E_USER_ERROR);
    $this->ip_address = $ip_address;

    // Create the socket
    $this->socket();
  }



  /*------------------------------------------------------------------------------
    Convert a value to a hex number
  ------------------------------------------------------------------------------*/
  private static function hex($value)
  {
    return str_pad(dechex($value & 0xFF), 2, '0', STR_PAD_LEFT);
  }



  /*------------------------------------------------------------------------------
    Create a checksum
  ------------------------------------------------------------------------------*/
  private static function checksum($hex)
  {
    $checksum = 0;
    for($i = 0; $i < strlen($hex); $i += 2)
      $checksum += hexdec($hex[$i].$hex[$i + 1]);

    return self::hex($checksum);
  }



  /*------------------------------------------------------------------------------
    Create the socket
  ------------------------------------------------------------------------------*/
  private function socket()
  {
    // Check to see if we already have a socket
    if($this->socket !== NULL)
      return TRUE;

    // Create the socket
    if(($this->socket = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP)) === FALSE)
      return 'Unable to create a UDP socket';

    // Bind to the socket
    if(socket_bind($this->socket, '0.0.0.0', $this->port) === FALSE)
      return 'Unable to bind to the UDP socket';

    return TRUE;
  }



  /*------------------------------------------------------------------------------
    Send data
  ------------------------------------------------------------------------------*/
  private function send($send, $expect, &$response = '')
  {
    // Throttle
    $time = microtime(TRUE);
    if($time < $this->next_send)
      usleep(round(($this->next_send - $time) * 1000000));

    // Flush the buffer
    socket_recvfrom($this->socket, $receive, 1024, MSG_DONTWAIT, $this->ip_address, $this->port);

    // Send our request
    $send = hex2bin($send);
    if(socket_sendto($this->socket, $send, strlen($send), 0, $this->ip_address, $this->port) === FALSE)
      return FALSE;


    // Attempt to receive the expected response up the to the timeout or if the other side disconnects (returns 0)
    $response = '';
    $quit_time = microtime(TRUE) + self::SEND_TIMEOUT;
    while(microtime(TRUE) < $quit_time && ($bytes = socket_recvfrom($this->socket, $receive, 1024, MSG_DONTWAIT, $this->ip_address, $this->port)) !== 0)
    {
      // Receive data and reset the timeout
      if($bytes > 0)
      {
        $response .= bin2hex($receive);

        if(str_contains($response, $expect))
        {
          $this->next_send = microtime(TRUE) + self::MAX_RATE;
          return TRUE;
        }

        $quit_time = microtime(TRUE) + self::SEND_TIMEOUT;
      }

      usleep(10000);
    }

    return FALSE;
  }



  /*------------------------------------------------------------------------------
    Get a session
  ------------------------------------------------------------------------------*/
  private function session($force = FALSE)
  {
    // Check to see if we already have a session
    if(!$force && $this->session !== NULL && $this->next_send + self::SESSION_TIMEOUT - self::MAX_RATE > microtime(TRUE))
      return TRUE;

    // Request a session, try a couple of times
    if(!$this->send(self::GET_SESSION, self::RECEIVE_SESSION, $response))
      if(!$this->send(self::GET_SESSION, self::RECEIVE_SESSION, $response))
        return 'Could not obtain a session with the iBox: '.$response;

    // Remove anything from the start of the session data we dont need
    $response = substr($response, strpos($response, self::RECEIVE_SESSION));

    // Check its the correct length
    if(strlen($response) != 44)
      return 'Invalid session data received from iBox: '.$response;

    // Set session and serial
    $this->session = substr($response, 38, 4);
    $this->serial = 1;

    return TRUE;
  }



  /*------------------------------------------------------------------------------
    Execute an action
  ------------------------------------------------------------------------------*/
  protected function execute($action, $command, $zone = 0, $values = '000000')
  {
    // Validation
    if(strlen($action) !== 2)
      trigger_error('Invalid action', E_USER_ERROR);

    if(strlen($command) !== 6)
      trigger_error('Invalid command', E_USER_ERROR);

    if(round($zone) != $zone || $zone < 0 || $zone > 4)
      trigger_error('Zone must be 0 (all zones) or between 1 and 4', E_USER_ERROR);


    // Get a socket
    if(($error = $this->socket()) !== TRUE)
      return $error;


    // Get a session
    if(($error = $this->session()) !== TRUE)
      return $error;


    // Construct the parts we need
    $send = $action.self::PASSWORD.$command.$values.self::hex($zone);
    $checksum = self::checksum($send);
    $serial = self::hex($this->serial);


    // Send the command and check for a response
    $this->serial++;
    $retry = 0;
    while($retry++ < self::EXECUTE_RETRY_LOOPS)
    {
      // Try a send
      if($this->send(self::SEND.$this->session.'00'.$serial.'00'.$send.'00'.$checksum, self::RECEIVE.'00'.$serial.'00'))
        return TRUE;

      // Attempt to get a new session after EXECUTE_SESSION_TRY attempts
      if($retry == self::EXECUTE_SESSION_TRY)
      {
        if(($error = $this->session(TRUE)) !== TRUE)
          return $error;
        $serial = self::hex($this->serial);
        $this->serial++;
      }
    }

    return 'Gave up attempting to get a response from the iBox';
  }



  /*------------------------------------------------------------------------------
    RGBWW link
  ------------------------------------------------------------------------------*/
  public function rgbww_link($zone = NULL)
  {
    if($zone === NULL)
      trigger_error('Please supply a zone to rgbww_link($zone);', E_USER_ERROR);

    for($i = 1; $i <= self::LINK_LOOPS; $i++)
    {
      if(($error = $this->execute(self::LINK, self::RGBWW_LINK_UNLINK, $zone)) !== TRUE)
        return $error;

      sleep(1);
    }

    return TRUE;
  }



  /*------------------------------------------------------------------------------
    RGBWW unlink
  ------------------------------------------------------------------------------*/
  public function rgbww_unlink($zone = NULL)
  {
    if($zone === NULL)
      trigger_error('Please supply a zone to rgbww_link($zone);', E_USER_ERROR);

    for($i = 1; $i <= self::LINK_LOOPS; $i++)
    {
      if(($error = $this->execute(self::UNLINK, self::RGBWW_LINK_UNLINK, $zone)) !== TRUE)
        return $error;

      sleep(1);
    }

    return TRUE;
  }



  /*------------------------------------------------------------------------------
    RGBWW On
  ------------------------------------------------------------------------------*/
  public function rgbww_on($zone = 0)
  {
    $result = $this->execute(self::COMMAND, self::RGBWW_ON, $zone);

    $this->next_send += self::WAKEUP_TIME;

    return $result;
  }



  /*------------------------------------------------------------------------------
    Lamp On
  ------------------------------------------------------------------------------*/
  public function lamp_on()
  {
    return $this->execute(self::COMMAND, self::IBOX_LAMP_ON);
  }



  /*------------------------------------------------------------------------------
    RGBWW Off
  ------------------------------------------------------------------------------*/
  public function rgbww_off($zone = 0)
  {
    return $this->execute(self::COMMAND, self::RGBWW_OFF, $zone);
  }



  /*------------------------------------------------------------------------------
    Lamp Off
  ------------------------------------------------------------------------------*/
  public function lamp_off()
  {
    return $this->execute(self::COMMAND, self::IBOX_LAMP_OFF);
  }



  /*------------------------------------------------------------------------------
    RGBWW Night Mode
  ------------------------------------------------------------------------------*/
  public function rgbww_night($zone = 0)
  {
    return $this->execute(self::COMMAND, self::RGBWW_NIGHT, $zone);
  }



  /*------------------------------------------------------------------------------
    RGBWW Mode
  ------------------------------------------------------------------------------*/
  public function rgbww_mode($mode = 0, $zone = 0)
  {
    if(round($mode) != $mode || $mode < 0 || $mode > 9)
      trigger_error('Mode must be between 0 and 9', E_USER_ERROR);

    return $this->execute(self::COMMAND, self::RGBWW_MODE.self::hex($mode), $zone);
  }



  /*------------------------------------------------------------------------------
    Lamp Modes
  ------------------------------------------------------------------------------*/
  public function lamp_mode($mode = 0)
  {
    if(round($mode) != $mode || $mode < 0 || $mode > 9)
      trigger_error('Mode must be between 0 and 9', E_USER_ERROR);

    return $this->execute(self::COMMAND, self::IBOX_LAMP_MODE.self::hex($mode));
  }



  /*------------------------------------------------------------------------------
    RGBWW Modes Speed Adjust
  ------------------------------------------------------------------------------*/
  public function rgbww_mode_speed_adjust($adjust = 0, $zone = 0)
  {
    if(round($adjust) != $adjust || $adjust < -10 || $adjust > 10)
      trigger_error('Speed adjust must be between -100 and 100', E_USER_ERROR);

    while($adjust !== 0)
    {
      if(($error = $this->execute(self::COMMAND, $adjust > 0 ? self::RGBWW_FASTER : self::RGBWW_SLOWER, $zone)) !== TRUE)
        return $error;

      $adjust += $adjust > 0 ? -1 : 1;
    }
  }



  /*------------------------------------------------------------------------------
    Lamp Modes Speed Adjust
  ------------------------------------------------------------------------------*/
  public function lamp_mode_speed_adjust($adjust = 0)
  {
    if(round($adjust) != $adjust || $adjust < -10 || $adjust > 10)
      trigger_error('Speed adjust must be between -100 and 100', E_USER_ERROR);

    while($adjust !== 0)
    {
      if(($error = $this->execute(self::COMMAND, $adjust > 0 ? self::IBOX_LAMP_FASTER : self::IBOX_LAMP_SLOWER)) !== TRUE)
        return $error;

      $adjust += $adjust > 0 ? -1 : 1;
    }
  }



  /*------------------------------------------------------------------------------
    RGBWW Brightness
  ------------------------------------------------------------------------------*/
  public function rgbww_brightness($brightness = 100, $zone = 0)
  {
    if(round($brightness) != $brightness || $brightness < 0 || $brightness > 100)
      trigger_error('Brightness must be between 0 and 100', E_USER_ERROR);

    return $this->execute(self::COMMAND, self::RGBWW_BRIGHTNESS.self::hex($brightness), $zone);
  }



  /*------------------------------------------------------------------------------
    Lamp Brightness
  ------------------------------------------------------------------------------*/
  public function lamp_brightness($brightness = 100)
  {
    if(round($brightness) != $brightness || $brightness < 0 || $brightness > 100)
      trigger_error('Brightness must be between 0 and 100', E_USER_ERROR);

    return $this->execute(self::COMMAND, self::IBOX_LAMP_BRIGHTNESS.self::hex($brightness));
  }



  /*------------------------------------------------------------------------------
    RGBWW Brightness
  ------------------------------------------------------------------------------*/
  public function rgbww_saturation($saturation = 100, $zone = 0)
  {
    if(round($saturation) != $saturation || $saturation < 0 || $saturation > 100)
      trigger_error('Saturation must be between 0 and 100', E_USER_ERROR);

    return $this->execute(self::COMMAND, self::RGBWW_SATURATION.self::hex($saturation), $zone);
  }



  /*------------------------------------------------------------------------------
    RGBWW White
  ------------------------------------------------------------------------------*/
  public function rgbww_white($temperature = 100, $zone = 0)
  {
    if(round($temperature) != $temperature || $temperature < 0 || $temperature > 100)
      trigger_error('Colour temperature must be between 0 and 100', E_USER_ERROR);

    return $this->execute(self::COMMAND, self::RGBWW_WHITE.self::hex($temperature), $zone);
  }



  /*------------------------------------------------------------------------------
    Lamp White
  ------------------------------------------------------------------------------*/
  public function lamp_white($temperature = 100)
  {
    if(round($temperature) != $temperature || $temperature < 0 || $temperature > 100)
      trigger_error('Colour temperature must be between 0 and 100', E_USER_ERROR);

    return $this->execute(self::COMMAND, self::IBOX_LAMP_WHITE.self::hex($temperature));
  }



  /*------------------------------------------------------------------------------
    RGBWW RGB
  ------------------------------------------------------------------------------*/
  public function rgbww_color($color = 0, $zone = 0)
  {
    if(round($color) != $color || $color < 0 || $color > 255)
      trigger_error('Color must be between 0 and 255', E_USER_ERROR);

    $color = self::hex($color);

    return $this->execute(self::COMMAND, self::RGBWW_COLOR.$color, $zone, $color.$color.$color);
  }



  /*------------------------------------------------------------------------------
    Lamp RGB
  ------------------------------------------------------------------------------*/
  public function lamp_color($color = 0)
  {
    if(round($color) != $color || $color < 0 || $color > 255)
      trigger_error('Color must be between 0 and 255', E_USER_ERROR);

    $color = self::hex($color);

    return $this->execute(self::COMMAND, self::IBOX_LAMP_COLOR.$color, 0, $color.$color.$color);
  }


}

