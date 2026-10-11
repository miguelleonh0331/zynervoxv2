FROM hwdsl2/openvpn-server@sha256:8f9b421bc4b510f7d2e7d43d10bafc873de953fd42f7df00938ac90cf4937243
RUN apk add --no-cache python3 && mv /usr/sbin/openvpn /usr/sbin/openvpn-real
COPY admin/ /opt/vpn-admin/
RUN chmod 755 /opt/vpn-admin/*.sh /opt/vpn-admin/event.py \
    && ln -s /opt/vpn-admin/openvpn-wrapper.sh /usr/sbin/openvpn
CMD ["/opt/vpn-admin/start.sh"]
