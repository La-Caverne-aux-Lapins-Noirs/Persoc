# Persoc installation

Persoc is installed as a system service on lab machines. It is the local agent that:

- reports machine/user activity to Distrans;
- retrieves and applies the remote deadlist firewall rules;
- asks Distrans whether the current machine is in exam mode;
- applies the exam firewall;
- terminates graphical sessions that are not allowed in the current exam state.

Persoc is not a user-facing command. It is meant to run as `root`, because it needs access to nftables, session termination and system activity information.

## Debian package behaviour

The Debian package installs:

- the PHP sources in `/usr/lib/persoc/`;
- the launcher in `/usr/libexec/persoc`;
- the default configuration in `/etc/persoc/persoc.dab`;
- the configuration overlay directory `/etc/persoc/conf.d/`;
- the systemd service `persoc.service`;
- the log directory `/var/log/persoc/`;
- an empty `/etc/persoc/deadlist.csv` if no local deadlist already exists.

With debhelper/systemd integration, `persoc.service` is installed as a normal system service. On a systemd machine, it can be managed with:

```sh
sudo systemctl status persoc.service
sudo systemctl enable --now persoc.service
sudo systemctl restart persoc.service
sudo journalctl -u persoc.service -f
```

The historical `install.sh` helper now does the same basic service activation. It is only a convenience helper; package installation should rely on the Debian maintainer scripts.

## Required configuration

Persoc loads its configuration through `mergeconf`.

Configuration lookup order is:

1. `./persoc.dab` when running from the source tree;
2. `$HOME/persoc.dab`;
3. `/etc/persoc/persoc.dab`.

The package installs `/etc/persoc/persoc.dab`. Local overrides can be placed in `/etc/persoc/conf.d/`, because the default file contains:

```dab
@include "conf.d/"
```

The most important fields are:

```dab
Distrans = "192.168.200.1"
Deadlist = "/etc/persoc/deadlist.csv"
LocalUser = "technocore"
Custom = "192.168.200.1"

[Intervals
  Tick = 1
  Activity = 5
  Intruders = 5
  Deadlist = 59
]
```

`Distrans` is the host contacted through SSH by `send_data()`.

`Custom` is an allowlist used by the exam firewall. It should contain the endpoints that must remain reachable during an exam, in addition to Distrans itself.

`LocalUser` is protected by `persoc_kill_graphical_session()` and will not be killed by the intruder expulsion logic. It should be the local technical/admin user, not a student account.

`Deadlist` is the local CSV file written by `get_new_deadlist()` and read by `firewall_deadlist()`. The package creates an empty `/etc/persoc/deadlist.csv` on install so that the first service start does not fail before the first successful refresh.

## SSH access to Distrans

Persoc currently sends data to Distrans with:

```text
ssh -T -i /root/.ssh/ihk -p 4422 distrans@<Distrans>
```

So the machine must have:

- `/root/.ssh/ihk`, readable only by root;
- the corresponding public key authorized on the Distrans host;
- network access to the Distrans SSH port.

A minimal manual ping can be tested by running the Persoc packet code from the source tree, or by using Distrans' own test tooling. The important point is that Persoc expects a JSON response on stdout.

## Runtime dependencies

The package depends on the tools Persoc uses directly:

- `php-cli` for the daemon;
- `liblapin-utils` for `mergeconf`;
- `openssh-client` for communication with Distrans;
- `nftables` for firewall rules;
- `iproute2` for interface/IP/MAC discovery;
- `procps`, `bsdutils` and standard system tools for user/session/activity inspection;
- `systemd` for service management and `loginctl` session control.

## Logs

Persoc writes to:

```text
/var/log/persoc/persoc.log
```

It also writes service output to journald when started through systemd:

```sh
sudo journalctl -u persoc.service
```

## First production test

A safe first installation test should use a non-critical lab machine.

Recommended sequence:

1. Install the package.
2. Check `/etc/persoc/persoc.dab` and `/etc/persoc/conf.d/`.
3. Install `/root/.ssh/ihk` and verify its permissions.
4. Verify SSH connectivity to Distrans manually.
5. Start Persoc with `systemctl enable --now persoc.service`.
6. Watch `journalctl -u persoc.service -f` and `/var/log/persoc/persoc.log`.
7. Confirm that Distrans receives `persoc_log` and `log_activity` packets.
8. Test deadlist refresh before testing exam mode.
9. Test exam mode only with controlled fake/student accounts first.

## What not to do first

Do not enable exam mode on a real classroom until the following have been manually checked:

- the machine is correctly recognized by Distrans through MAC/IP;
- `LocalUser` is correct;
- `Custom` allows the required internal services;
- the Distrans answer to `is_exam` is correct;
- the expected graphical accounts are listed by `loginctl`;
- the firewall rules created by `firewall_exam(true)` match expectations.
