MiLight V6 PHP API
==================

- **Description:** PHP drivers for the V6 Mi-Light WiFi Bridge iBox2 AKA LimitlessLED and iBox lamp
- **Project Website:** [GitHub](https://github.com/NeonHorizon/milight)
- **Requirements:** An Ubuntu Linux installation or equivalent
- **License:** GPL Version 3

### Description

The iBox2 provides a WiFi gateway between the various wireless protocols used by different Mi-Lights and your local network so you can use mobile apps and cloud services (Alexa, Google Assistant etc) to control your lights.

This library provides a way for your own PHP scripts to control your MiLights via the iBox2 and can be incorporated into your own webpages, applications, etc.

It presumes you have a working setup where the iBox2 is configured on your WiFi and you can use the mobile app to control your lights.

---

### Installation instructions

Either [Download the latest zip file](https://github.com/NeonHorizon/milight/archive/master.zip) and extract it, or git clone https://github.com/NeonHorizon/milight.git

The project contains the following files...

* **milight.php** - The main library
* **examples** - An script with some examples for controlling MiLights
* **README.md** - This file
* **COPYING.txt** - The GPLv3 license

Presumably you already have PHP installed but if you don't install it:
```
sudo apt -y install php-cli
```

---

### Quick Start

The easiest way to make a start is to take a look at the [examples file](https://github.com/NeonHorizon/milight/blob/master/examples) which was part of your download. This includes descriptions of most of the commands you can perform.
You can test this script directly by editing the example file, entering the IP addresses of your iBox2, then running it from inside the directory:

```
cd milight
nano examples
./examples
```

---

### Using the Driver

Using the driver in your own scripts is fairly simple...

If you want to run your script directly from the command line it must start by telling Linux it's PHP and then include the php opening tag:
```
#!/usr/bin/php
<?php
```

Now we are in the PHP code; load the milight driver and tell it you want to use it by the name ibox:
```
require_once('milight.php');
use milight\ibox as ibox;
```

Open a connection to your iBox (replace 192.168.1.10 with your iBox's IP address) and call it $ibox:
```
$ibox = new ibox('192.168.1.10');
```

Send your first commands!

```
$ibox->rgbww_on();               // Turn on the lights
$ibox->rgbww_white(100);         // Set them as cold white
$ibox->rgbww_brightness(100);    // Set them as full brightness
```

---

### License Information

This program is free software: you can redistribute it and/or modify it under the terms of the GNU General Public License as published by the Free Software Foundation, either version 3 of the License, or (at your option) any later version.

This program is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the GNU General Public License for more details.

You should have received a copy of the GNU General Public License along with this program.  If not, see [http://www.gnu.org/licenses/](http://www.gnu.org/licenses/).

