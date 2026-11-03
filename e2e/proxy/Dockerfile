FROM alpine:3.20
RUN apk add --no-cache tinyproxy \
 && mkdir -p /var/log/tinyproxy /run/tinyproxy \
 && chown -R nobody:nobody /var/log/tinyproxy /run/tinyproxy
COPY tinyproxy.conf /etc/tinyproxy/tinyproxy.conf
EXPOSE 8888
CMD ["tinyproxy", "-d"]
