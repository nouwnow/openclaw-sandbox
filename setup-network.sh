#!/bin/bash
# Gebruik: sudo ./setup-network.sh
# Maakt de tap-interface en NAT aan voor de openclaw MicroVM.
# Gebruikt vmtap1 / 10.0.1.x zodat nanoclaw (vmtap0 / 10.0.0.x) naast deze VM kan draaien.
# Moet opnieuw uitgevoerd worden na een reboot (tenzij systemd-networkd gebruikt wordt).

USER_NAME="${SUDO_USER:-$(whoami)}"  # automatisch de aanroepende gebruiker
TAP_DEV="vmtap1"
HOST_IP="10.0.1.1"

# 1. Interface
ip tuntap add dev $TAP_DEV mode tap user $USER_NAME multi_queue
ip addr add $HOST_IP/24 dev $TAP_DEV
ip link set $TAP_DEV up

# 2. Routing
sysctl net.ipv4.ip_forward=1

# 3. NAT/Firewall
INT_IFACE=$(ip route | grep default | awk '{print $5}')
iptables -t nat -A POSTROUTING -o $INT_IFACE -j MASQUERADE
iptables -A FORWARD -m conntrack --ctstate RELATED,ESTABLISHED -j ACCEPT
iptables -A FORWARD -i $TAP_DEV -o $INT_IFACE -j ACCEPT

echo "Netwerk voor Openclaw MicroVM is klaar op $TAP_DEV ($HOST_IP)"
