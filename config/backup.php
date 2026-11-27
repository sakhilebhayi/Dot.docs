<?php

return [
    /*
    | Encrypts the nightly database and uploads backups (EA-6 / O-01). Set a long
    | random value in the host's .env and keep a copy in a password manager:
    | without it the backups cannot be restored. Empty = backups stay
    | unencrypted, as before, with a warning in the command's output.
    */
    'passphrase' => env('BACKUP_PASSPHRASE'),

    /* How many of each kind of backup to keep. */
    'keep' => (int) env('BACKUP_KEEP', 14),
];
