# Naming Conventions

<img src="../../images/naming.png" width="30" align="right"/>
This applies to all server types, physical, virtual & containerised, additionally networking devices such as gateways & wireless access points will follow this new naming convention.

*Server type, function, environment & deployment method will all be defined in MOTD on every server via Ansible, and in Proxmox notes & tags where applicable.*

Definition:  {   site   purpose   environment   identifier   }
Example 1:    {    ldndnsprd1   }
Example 2:    {    ldnusgprd1   }

| Element     | Type & Length | Purpose                        | Examples      |
| ----------- | ------------- | ------------------------------ | ------------- |
| Site        | 2/3 Letters   | Location of server             | ldn, mcr, az  |
| Purpose     | 2/3 Letters   | Purpose of server              | dns, app, fs  |
| Environment | 3 Letters     | Environment of server          | prd, tst, dev |
| Identifier  | 1 Digit       | Numerical instance of a server | 1, 2, 3       |
## Potential Names

| Server     | Purpose                 |     |     |
| ---------- | ----------------------- | --- | --- |
| ldnpveprd1 | PVE Host                |     |     |
| ldnpbsprd1 | PBS Host                |     |     |
| ldnfsprd1  | File Server             |     |     |
| ldndnsprd1 | DNS Server              |     |     |
| ldnappprd1 | Docker Host             |     |     |
| ldnupkprd1 | Uptime Kuma             |     |     |
| ldnimcprd1 | Immich Server           |     |     |
| ldnmgmprd1 | Management Server       |     |     |
| ldnpdmprd1 | PDM Host                |     |     |
| ldnghrprd1 | GitHub Actions Runner   |     |     |
| ldnadoprd1 | ADO Pipelines Runner    |     |     |
| ldncfdprd1 | Cloudflared Host        |     |     |
| ldnw11prd1 | W11 Jumphost            |     |     |
| ldnapptst1 | tst Docker Host         |     |     |
| ldnubtst1  | Generic Ubuntu Server   |     |     |
| ldnwstst1  | Generic Windows Server  |     |     |
|            |                         |     |     |
| mcrpveprd1 | Manchester Proxmox Host |     |     |
| mcrpbsprd1 | PBS Host                |     |     |
| mcrdnsprd1 | DNS                     |     |     |
| mcrappprd1 | Docker Host             |     |     |
| mcrcfdprd1 | Cloudflared             |     |     |
|            |                         |     |     |
| azdnsprd1  |                         |     |     |
| azsappprd1 |                         |     |     |
|            |                         |     |     |
|            |                         |     |     |
|            |                         |     |     |
