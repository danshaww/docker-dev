# Name Resolution

<img src="../images/bind.png" width="30" align="right"/>

- Name resolution in my environment is handled by Bind DNS
- All of my DNS servers are running Debian 12 & have a standard 2cpu/1GB ram specification
- Bind is installed & has initial configuration deployed via Ansible
- All DNS created dynamically via nsupdate, this is insecure at the time of writing, with dynamic updates coming from the following
- Dynamic updates are sent to the master server in London, with updates replicated to slave servers.

I will be adding secure DNS updates to this solution at a later date.

# Dynamic DNS Record Creation

## DHCP
Forward & Reverse DNS records are created for every client that retrieves an IP addres from DHCP

## Ansible
A list of records are defined in Ansible, where an application/system does not use DHCP & does not support dynamically registering it's own DNS records

## Linux (Static IP)
Machines that do not use DHCP will register their Forward & Reverse DNS records via a simple bash script that runs on startup via a cron job
This script & associated CRON job are baked into my VM Images

## Windows (Static IP)
Machines that do not use DHCP will register their Forward & Reverse DNS records via a Powershell script that runs on startup via a scheduled task
This script & associated scheduled task are baked into my VM Images

## Docker Applications
Apps with a web interface will have an Alpine linux container included in their deployment, with a single startup command configured to register a CNAME for the project, pointing to the host that is running the application, this will run each time the compose project is restarted

### DNS Servers
- ldndnsprd1 (Master)
- mcrdnsprd1 (Slave)
