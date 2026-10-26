[![License: AGPL v3](https://img.shields.io/badge/license-AGPL--3.0-blue.svg)](LICENSE)

# ASTPP 6.1 – hardened fork by infrahost

VoIP billing for FreeSWITCH, based on [ASTPP](https://github.com/iNextrix/ASTPP) 6.0 by
iNextrix Technologies Pvt. Ltd. This fork is maintained by infrahost. It focuses on security,
PHP 8.5, privacy and a reproducible installation on Debian 13. It adds no new billing features.

- **Origin:** iNextrix/ASTPP, branch `V6.0`, commit
  [`d5108ec`](https://github.com/iNextrix/ASTPP/commit/d5108ec6fb7a1ba7842155fb4e06632c629f3a63).
  The upstream history is kept unchanged below the fork commits, so every change can be
  traced with `git log d5108ec..`.
- **Compatibility with later upstream releases is not a goal.** Upstream fixes are ported by
  hand, and code taken over from upstream has to go through the PHP 8.5 rewriters
  (`astpp-debian13/tools/php85-port/`).
- **Installer:** [`astpp-debian13/`](astpp-debian13/) installs and updates this tree on
  Debian 13.

## What is different from ASTPP 6.0

**Security**

- Many vulnerabilities of ASTPP 6.0 are fixed. They include SQL injection (web interface and
  Lua dialplan), unauthenticated internal endpoints, object injection, unrestricted uploads,
  command injection, missing ownership checks between tenants (IDOR), manipulable prices and
  payments, the PayPal IPN handling and predictable random values.
- The fixes were reported to the vendor, iNextrix, in October 2026. This README deliberately
  gives no further details. Finding IDs such as `H2`, `M9` or `O5` in code comments and
  commit messages refer to the fork's internal security review.
- A central authorization hook (`application/hooks/access_control.php`) checks role and record
  ownership for every controller call.
- CSRF protection uses a token per session and an Origin/Referer check
  (`application/hooks/csrf_guard.php`, `assets/js/astpp_csrf.js`).
- A Content-Security-Policy is enforced: scripts need a nonce, and event handlers run without
  `eval` through `assets/js/astpp_csp.js`.
- Portal passwords are stored as Argon2id hashes. A password rule applies (at least 12
  characters, a local list of common passwords). "Forgot password" no longer reveals whether
  an account exists.
- Admin access can optionally be limited to allowed networks (`admin_allowed_ips`). A sync
  tool can fill the list from a central allowlist.
- The vendor API addon is removed. It is replaced by a new provisioning API (see below).
- Addons can no longer be installed through the web interface.

**PHP 8.5**

- ASTPP 6.0 only ran on PHP 7.4, which has had no security updates since 2022. The port keeps
  the PHP 7 behaviour through `application/php7compat.php`. The ported code was compared with
  the original by A/B tests (web interface, FreeSWITCH endpoints and CDRs, cron jobs, PDFs,
  imports).

**Privacy**

- The hidden vendor telemetry and the Google Analytics tag are removed, as are the license
  phone-home and hard-coded third-party credentials.
- Fonts and libraries are self-hosted, so pages make no requests to third parties.

**Libraries**

- Current, self-hosted versions with verified downloads: jQuery 3.7.1, Bootstrap 4.6.2,
  jQuery UI 1.14.2, bootstrap-select 1.13.18, jquery.validate 1.22.1, Chart.js 4.5.1
  (instead of the commercially licensed Highcharts), TinyMCE 8 (instead of CKEditor 4),
  TCPDF 6.11.4 and html2pdf 4.6.1.

**Provisioning API**

- `/api/v1` is a JSON API for customers, SIP devices, packages, balance, package usage and
  call records. Access uses API keys that are bound to allowed IP addresses. Admins,
  resellers and customers manage the keys in the portal under *API Access*.
- The documentation is shown in the portal and is part of the code
  ([`api.md`](web_interface/astpp/application/modules/apikeys/docs/api.md), in German, and
  [`openapi.yaml`](web_interface/astpp/application/modules/apikeys/docs/openapi.yaml)).

**Fixes and removals**

- Dialplan fixes: number localization before the blocklist and package checks, enforced
  reseller blocklists, packages as one minute pool, clean rejection of disabled or expired
  accounts.
- Dead code, demo sites and unused libraries are removed.
- Vendor advertising is removed, including from notification mails.

**Appearance**

- Version 6.1 uses the infrahost logo, colours and the footer "© infrahost – Based on
  ASTPP © iNextrix Technologies Pvt. Ltd.". Resellers can still use their own logos and
  footers.

## Installation (Debian 13)

Requirements:

- a freshly installed Debian 13 "trixie" (amd64) with 2 CPU cores, 4 GB RAM and 20 GB disk
- a DNS name that points to the server
- open ports 22/tcp, 80/tcp, 443/tcp, 5060/udp+tcp and 16384–32768/udp

As root:

```bash
git clone https://github.com/infrahost-ch/astpp-hardened.git /root/astpp
```

```bash
cd /root/astpp/astpp-debian13 && cp install.conf.example install.conf && nano install.conf
```

```bash
bash /root/astpp/astpp-debian13/install.sh
```

- `ASTPP_FQDN` (the public host name) is required. All other settings are optional.
- The installer sets up MariaDB 11.8, PHP 8.5 (Sury), nginx with Let's Encrypt, FreeSWITCH 1.11
  (packages with a SignalWire token, or built from source), nftables, fail2ban and cron.
- Keys and passwords are generated per installation. The credentials are written to
  `/root/astpp-credentials.txt` and never printed to the terminal.

Update an existing installation to the current state of the repository. The first command is
a dry run; the second makes a backup first and then checks the result:

```bash
DRY_RUN=yes bash /root/astpp/astpp-debian13/tools/astpp-deploy.sh
```

```bash
bash /root/astpp/astpp-debian13/tools/astpp-deploy.sh
```

The installer documentation (`astpp-debian13/README.md`) is in German. It covers the
decisions behind the installer, operation, the firewall, CSP and the allowlist.

**Status:** this fork runs in production on Debian 13 with PHP 8.5 and is updated with the
deploy script. A fresh installation from this repository has not yet been re-tested end to
end.

## License

ASTPP and this fork are free software under the
[GNU Affero General Public License version 3](LICENSE), without any warranty.

- The copyright notices of the original authors stay in the source files (see `Authors`).
- Origin and changes are described in [`NOTICE`](NOTICE).
- Third-party components keep their own licenses (see the LICENSE files under
  `web_interface/astpp/assets/vendor/` and
  `web_interface/astpp/application/libraries/html2pdf/`).
- If you offer a modified version to users over a network, the AGPL requires you to offer
  them its source code as well. Every page of the web interface and the login page link to
  the source code ("Source code"). By default the link points to this repository; set
  `source_url` in `/var/lib/astpp/astpp-config.conf` to the source of your version.
- The infrahost logos are trademarks of infrahost and are not covered by the AGPL. If you
  redistribute this software, replace them with your own (see `NOTICE`).

## Credits

ASTPP was created by Darren Wiebe and is developed by
[iNextrix Technologies Pvt. Ltd.](https://www.inextrix.com) and the ASTPP contributors. This
fork would not exist without their work. The original README is kept as
[`README-upstream.md`](README-upstream.md).
