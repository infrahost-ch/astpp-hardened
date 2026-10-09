# ASTPP 6.1 (infrahost fork)

VoIP billing for FreeSWITCH, based on [ASTPP](https://github.com/iNextrix/ASTPP) V6.0 by
iNextrix Technologies Pvt. Ltd. and maintained by infrahost as a hardened fork: security
fixes, PHP 8.5, current self-hosted browser and PDF libraries, no telemetry or third-party
requests, no vendor advertising. Compatibility with future upstream releases is not a goal;
upstream fixes are ported by hand.

- Installation and updates: `../astpp-debian13/` (`install.sh`, `tools/astpp-deploy.sh`)
- Changes: `git log -- astpp` (each change is its own commit, starting from the upstream import)
- License: GNU AGPL v3 (`LICENSE`), origin and copyright: `NOTICE`, `Authors`
- Original README of the upstream project: `README-upstream.md`
