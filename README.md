# Log Sender

Settings `curlsecurityblockedhosts` and `curlsecurityallowedport` must be adjusted in **Site administration > Security > HTTP security**.

# Setup

## Create a custom host

Moodle blocks outgoing connections via cURL based on the configuration of its `curlsecurityblockedhosts` setting.

Since we need to use an SSH tunnel to communicate with the log server, we must to set a custom hostname bound to the SSH tunnel and whitelist it in Moodle.

1. Edit `/etc/hosts`:

```
127.0.0.1 log-tunnel.local
```

2. Create the SSH tunnel using the hostname:

```conf
[Unit]
Description=SSH Tunnel to the log server
After=network.target

[Service]
User=user
ExecStart=/usr/bin/ssh -N \
  -L log-tunnel.local:8080:localhost:8080 \
  -i /home/user/.ssh/id_log_tunnel \
  -o ExitOnForwardFailure=yes \
  -o ServerAliveInterval=60 \
  user@log_server_ip
Restart=always
RestartSec=10

[Install]
WantedBy=multi-user.target
```

```sh
sudo systemctl daemon-reload
sudo systemctl enable log-tunnel.service
sudo systemctl restart log-tunnel.service
```

Test the SSH connection to the remote server:

```sh
ping -c 1 log-tunnel.local
ssh -i /home/user/.ssh/id_log_tunnel user@log_server_ip
```
