## Code Storage & CI/CD <img src="../images/actions.png" width="40" align="right"/>

Gitea is responsible for the storage of all of my GIT repositories along with being my primary CI/CD tool for running automated tasks such as executing Ansible playbooks & executing Terraform jobs.

A number of repositories on Gitea are mirrored to GitHub, this is primarily to allow public access rather than as a means of backup, my Gitea instance is backed up via rsync & via PBS in addition to the local clones of all of my repo's acting as a restore method.

I have CI/CD jobs available for the following
	- Ansible Playbook execution
	- Terraform execution
	- Docker Image build & publish to Gitea image registry


## Server Configuration <img src="../images/ansible.png" width="40" align="right"/>

Automation of configuration & installation of my servers & software is handled via Ansible, with the configuration of a range of services such as Docker, DNS and DHCP handled by custom Ansible roles I have built.

Ansible roles are stored in a single dedicated repo, with all of my playbooks, inventory & Ansible Vault being stored in a separate repository.

Secrets in use in my Ansible deployments live in a single Ansible Vault file that is accessed as vars in my inventory.

### Ansible Responsibilities
- Global Configuration - playbooks deploying roles containing my base user/server configuration to all machines, resulting in a consistent experience on all machines
- Update deployment - updates are deployed via a nightly playbook run against all machines excluding Proxmox hosts & PBS hosts
- Application/Service deployment & configuration
	- DNS
	- DHCP
	- Docker
	- Cloudflared
	- NGINX
	- PDM (Deployment only)
	- CheckMK (Deployment only)