All of the alerts in my environment are sent via Discord using webhooks.

## CheckMK <img src="../images/checkmk.png" width="30" align="right"/>
- Primary monitoring solution
- Monitoring servers & their metrics such as CPU, memory & disk space usage
- Additionally monitoring the status of my web based services.
- Hosted on a dedicated VM in London & reaches all hosts via the CheckMK agent which is deployed via Ansible.

## Uptime Kuma <img src="../images/uptimekuma.png" width="30" align="right"/>
- I am hosting instances of Uptime Kuma on Docker on both sites of my environment.
- This is solely used for monitoring of the S2S VPN at present

## Uptime Robot <img src="../images/uptimerobot.png" width="30" align="right"/>
- Uptime Robot is a cloud hosted monitoring solution
- This tools is used to monitor the uptime of my Manchester & London networks via their DDNS hostnames

