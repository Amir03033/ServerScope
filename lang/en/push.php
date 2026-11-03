<?php

// Push notifications on this device (profile page, resources/js/install.js).
return [
    'title' => 'Push notifications on this device',
    'not_configured' => 'Push is not set up on this ServerScope yet (an administrator adds the keys).',
    'devices' => 'No device has push on yet.|Push is on for :count device.|Push is on for :count devices.',
    'js' => [
        'checking' => 'Checking this browser…',
        'on' => 'On: incidents and changes reach this device, following your preferences above.',
        'off' => 'Off on this device. Turn it on to get incidents as phone or desktop notifications.',
        'blocked' => 'Blocked in this browser\'s settings. Allow notifications for this site to turn push on.',
        'unsupported' => 'This browser cannot receive push notifications. On iPhone: add ServerScope to your home screen first (Share → Add to Home Screen).',
        'turn_on' => 'Turn on',
        'turn_off' => 'Turn off',
        'working' => 'One moment…',
        'failed' => 'That did not work. Try again.',
    ],
];
