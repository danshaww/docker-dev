<img src="../../images/docker.png" width="40" align="right"/>
Docker is my primary means of application hosting, currently all of my applications run in containers, with applications split across a range of hosts, some hosting a single application & others hosting multiple

---
## Management
- Applications are deployed via Compose projects, allowing containers and networking to be grouped together
- Compose deployment is handled via Ansible using Jinja2 templates along with the creation of bind mount locations
- Secrets are stored in Ansible Vault and retrieved when compose templates are rendered
- SSH directly to my Docker hosts is used for troubleshooting, viewing logs & monitoring resource usage
---
## Ingress
- All web based applications are exposed using Traefik with Lets Encrypt providing TLS encryption for all services
- Non web based services such as MySQL, Minecraft & SMTP will be exposed using a port mapping on the host
---
## Networking
- All Compose projects have a dedicated 'backend' network defined for internal service traffic
- All Compose projects with a frontend will be attached to the Traefik network
- Services requiring connectivity to my containerised SMTP relay will be attached to it's network
---
## Persistent Data
- I am storing all persistent data in bind mounted local volumes on the host
- Volumes are backed up using Rsync & PBS
- Volumes are not deleted when compose projects are removed
