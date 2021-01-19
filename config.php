<?php
/*
 *  wordpress-lti - WordPress module to add LTI support
 *  Copyright (C) 2020  Simon Booth, Stephen P Vickers
 *
 *  This program is free software; you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License as published by
 *  the Free Software Foundation; either version 2 of the License, or
 *  (at your option) any later version.
 *
 *  This program is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *
 *  You should have received a copy of the GNU General Public License along
 *  with this program; if not, write to the Free Software Foundation, Inc.,
 *  51 Franklin Street, Fifth Floor, Boston, MA 02110-1301 USA.
 *
 *  Contact: s.p.booth@stir.ac.uk
 */

use ceLTIc\LTI\Util;

define('LTI_ID_SCOPE_DEFAULT', '0'); // ID only
define('LTI_LOG_LEVEL', Util::LOGLEVEL_DEBUG);
define('LTI_SIGNATURE_METHOD', 'RS256');
define('LTI_KID', '');  // A random string to identify the key value
define('LTI_PRIVATE_KEY', <<< EOD
-----BEGIN RSA PRIVATE KEY-----
MIICWwIBAAKBgGHnnqmt+3m2AxPpgU2Qeir8tAiOBIbfcQcure/cqyk+Z7nEaNPj
frkBYnFgpOdhtv0dr4dK6BGOi2JT8VEdUOPLYjJRS94/2FPkVEtsjm0gKwnZEwl6
LnS+Z0mfoyUJSDNfDf4QeglhAK1ctPsCsgyKafjMIFY9ovMp+d/HMBPHAgMBAAEC
gYAA5196l4WTyQ9cNrKf4a6PCQgscAswp41mkJLAfRwDZHUWrO5+zkHUOWQMQeUj
0a4bxhNhv1pHFzbIMJgwtIGTpccvtsLC4mVDN0Ev3I1WOyZeGug4KmFKVcdcXyEx
04DczXC7dFXR67Y5qpoMTnKbvKKe+vnh81qtjW3hokhwyQJBAMNGrFfdjb2lT0WQ
tHBq9KM06UJ11yE+a5zelFLdtY5BjhnYvm50wZWDiAAE0zmfSiWVvc6fR39E7zpo
gHXPc1sCQQCAWYRN26KID6OAjPScAHEWuzPvwmb8gAYjx0T1ya+AqJQ3cPS1XnRs
OG/ocf4bnyn10JW2eXjaB0+h8xdQf+kFAkEAtHz4AmZ3AdhvQp8TB+zznH3lM1Zz
tvhYwq8/bLAbhRa2XtFkgfdMjgL6ivnquZGvGLokq3uwu8NdUiEQytMpjQJAcJMT
cl60PfbJh9UaL0JL7o4fzamLPujjebosCBDwOD6kUcRnPjUslEckEJL7OCrwWMSs
q7H7h/BlrjxTNK4cKQJAGdkakyQAuF+qJqun3XrrJa+H5kX0E4C2x+kPSdm+EIlR
tmkuma2LS/ySzmsvjgn6CZFGV9An5Vg+1kagUmrCjQ==
-----END RSA PRIVATE KEY-----
EOD
);

###
###  Registration settings
###
define('AUTO_ENABLE', false);
define('ENABLE_FOR_DAYS', 0);
?>
