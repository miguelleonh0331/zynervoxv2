#!/bin/sh
# Add audited hooks to the upstream launch without editing generated PKI/config.
case " $* " in
  *" --config "*) ;;
  *) exec /usr/sbin/openvpn-real "$@" ;;
esac
exec /usr/sbin/openvpn-real "$@" --script-security 2 \
  --client-connect /opt/vpn-admin/event.py --client-disconnect /opt/vpn-admin/event.py \
  --status /run/openvpn.status 5 --status-version 3 \
  --management 127.0.0.1 7505
