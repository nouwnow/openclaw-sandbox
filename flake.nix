{
  description = "Openclaw MicroVM Sandbox";

  inputs = {
    nixpkgs.url    = "github:NixOS/nixpkgs/nixos-unstable";
    microvm.url    = "github:astro/microvm.nix";
    microvm.inputs.nixpkgs.follows = "nixpkgs";
    nix-openclaw.url = "github:openclaw/nix-openclaw";
    nix-openclaw.inputs.nixpkgs.follows = "nixpkgs";
  };

  outputs = { self, nixpkgs, microvm, nix-openclaw }:
  let
    hostUser      = "michiel";
    hostWorkspace = "/home/${hostUser}/openclaw-workspace";
  in {
    nixosConfigurations.openclaw-vm = nixpkgs.lib.nixosSystem {
      system = "x86_64-linux";
      modules = [
        microvm.nixosModules.microvm
        nix-openclaw.nixosModules.openclaw-gateway

        ({ pkgs, lib, ... }: {
          # Openclaw is gemarkeerd als insecure (LLM-toegang tot systeem).
          # We draaien het bewust geïsoleerd in een MicroVM.
          nixpkgs.config.permittedInsecurePackages = [
            "openclaw-2026.3.12"
          ];
          nixpkgs.overlays = [ nix-openclaw.overlays.default ];

          networking.hostName = "openclaw-agent";
          # Afwijkend subnet van nanoclaw (10.0.0.x) — beide VMs draaien parallel
          networking.interfaces.eth0.ipv4.addresses = [ {
            address = "10.0.1.2";
            prefixLength = 24;
          } ];
          networking.defaultGateway = { address = "10.0.1.1"; interface = "eth0"; };
          networking.useNetworkd   = false;
          networking.nameservers   = [ "1.1.1.1" "8.8.8.8" ];
          system.stateVersion      = "23.11";

          # ── MicroVM ────────────────────────────────────────────
          microvm = {
            hypervisor = "cloud-hypervisor";
            socket     = "control.sock";
            mem        = 8192;
            vcpu       = 4;
            vsock.cid  = 42;

            interfaces = [ {
              type = "tap";
              id   = "vmtap1";
              mac  = "02:00:00:00:00:02";
            } ];

            virtiofsd.group            = null;
            virtiofsd.inodeFileHandles = "never";
            virtiofsd.extraArgs        = [ "--sandbox=none" "--log-level=debug" ];

            virtiofsd.package = pkgs.writeShellScriptBin "virtiofsd" ''
              args=()
              for arg in "$@"; do
                case "$arg" in
                  --posix-acl) ;;
                  *) args+=( "$arg" ) ;;
                esac
              done
              while true; do
                ${pkgs.virtiofsd}/bin/virtiofsd "''${args[@]}" >> /tmp/vfs-openclaw.log 2>&1
                echo "[virtiofsd] gestopt (exit $?), herstart over 1s..." >> /tmp/vfs-openclaw.log
                sleep 1
              done
            '';

            volumes = [ {
              image      = "nix-store-rw.img";
              mountPoint = "/nix/.rw-store";
              size       = 2048;
            } ];

            shares = [
              { source = "/nix/store";                   mountPoint = "/nix/store";            tag = "ro-store";      proto = "virtiofs"; }
              { source = hostWorkspace;                  mountPoint = "/home/agent/workspace"; tag = "openclaw-data"; proto = "virtiofs"; }
              { source = "${hostWorkspace}/.claude";     mountPoint = "/home/agent/.claude";   tag = "agent-claude";  proto = "virtiofs"; }
              { source = "${hostWorkspace}/.npm-global"; mountPoint = "/home/agent/.npm-global"; tag = "agent-npm";   proto = "virtiofs"; }
            ];
          };

          # ── Packages ───────────────────────────────────────────
          environment.systemPackages = with pkgs; [
            python311 nodejs_20 corepack_20
            curl git gh ffmpeg
            openclaw   # batteries-included: gateway + Discord + alle extensies
          ];

          virtualisation.docker.enable = true;

          networking.firewall.allowedTCPPorts = [ 3333 18790 18791 ];

          # Credential proxy poort voor Docker containers
          networking.firewall.extraCommands = ''
            iptables -I INPUT -i docker0 -p tcp --dport 3001 -j ACCEPT
          '';

          # uid/gid = 1000 (zelfde als host michiel → virtiofs schrijfrechten)
          users.groups.agent.gid = 1000;
          users.users.agent = {
            isNormalUser = true;
            uid          = 1000;
            group        = "agent";
            extraGroups  = [ "wheel" "docker" ];
            password     = "agent";
          };

          security.sudo.wheelNeedsPassword = false;

          environment.sessionVariables.NPM_CONFIG_PREFIX = "/home/agent/workspace/.npm-global";
          programs.bash.interactiveShellInit = ''
            export PATH="/home/agent/.npm-global/bin:$PATH"
          '';

          systemd.tmpfiles.rules = [
            "L+ /home/agent/.claude.json - - - - /home/agent/workspace/.claude.json"
            # Openclaw state directories (virtiofs → persistent op host)
            "d /home/agent/workspace/.openclaw/logs                    0755 agent agent -"
            "d /home/agent/workspace/.openclaw/agents/main/agent       0755 agent agent -"
            # Per-agent output directories — voor fase-specifieke opslag per agent
            "d /home/agent/workspace/.openclaw/agents/writer/agent     0755 agent agent -"
            "d /home/agent/workspace/.openclaw/agents/researcher/agent 0755 agent agent -"
            "d /home/agent/workspace/.openclaw/agents/editor/agent     0755 agent agent -"
            # Bundled plugins overlay — manifests ontbreken in Nix package (zie OPENCLAW-SETUP.md)
            "d /home/agent/workspace/.openclaw-bundled-plugins         0755 agent agent -"
            # Project-A gateway state directories
            "d /home/agent/workspace/project-a/.openclaw/logs                    0755 agent agent -"
            "d /home/agent/workspace/project-a/.openclaw/agents/coordinator/agent 0755 agent agent -"
            "d /home/agent/workspace/project-a/.openclaw/workspace/memory        0755 agent agent -"
          ];

          # ── Openclaw gateway — coordinator (poort 18789) ───────
          # Draait als systeem-service; agent user beheert config via Claude Code.
          services.openclaw-gateway = {
            enable       = true;
            package      = pkgs.openclaw;   # batteries-included met Discord extensie
            user         = "agent";
            group        = "agent";
            createUser   = false;
            stateDir     = "/home/agent/workspace/.openclaw";
            port         = 18789;
            environmentFiles = [ "/home/agent/workspace/.env" ];
            restart      = "always";
            logPath      = "/tmp/openclaw-gateway.log";
            # OPENCLAW_CONFIG_PATH in .env overschrijft dit — wijst naar workspace config
            config.gateway.mode = "local";
          };

          # ── Project-A gateway (poort 18790) ────────────────────
          # Tweede gateway voor project-isolatie. Eigen openclaw.json,
          # eigen workspace, eigen memory. Orchestrator (18789) routeert
          # taakopdrachten door naar project-a via het delegatie-protocol.
          # Gebruikt raw systemd service — nix-openclaw module ondersteunt
          # geen meerdere instanties via services.openclaw-gateway-*.
          systemd.services.openclaw-gateway-project-a = {
            description = "Openclaw Gateway — Project-A (poort 18790)";
            after       = [ "network.target" ];
            wantedBy    = [ "multi-user.target" ];
            environment = {
              OPENCLAW_STATE_DIR    = "/home/agent/workspace/project-a/.openclaw";
              CLAWDBOT_STATE_DIR    = "/home/agent/workspace/project-a/.openclaw";
              OPENCLAW_CONFIG_PATH  = "/home/agent/workspace/project-a/.openclaw/openclaw.json";
              CLAWDBOT_CONFIG_PATH  = "/home/agent/workspace/project-a/.openclaw/openclaw.json";
            };
            serviceConfig = {
              User             = "agent";
              WorkingDirectory = "/home/agent/workspace/project-a/.openclaw";
              ExecStart        = "${pkgs.openclaw}/bin/openclaw gateway --port 18790";
              Restart          = "always";
              RestartSec       = "5s";
              StandardOutput   = "journal";
              StandardError    = "journal";
              SyslogIdentifier = "openclaw-project-a";
              EnvironmentFile  = "/home/agent/workspace/.env";
            };
          };

          # ── Mission Control dashboard (poort 3333) ─────────────
          # Next.js dashboard voor live monitoring van agents, sessies en taken.
          # Verbindt via WebSocket met de gateway op 127.0.0.1:18789.
          systemd.services.openclaw-dashboard = {
            description = "Openclaw Mission Control Dashboard";
            after       = [ "network.target" "openclaw-gateway.service" ];
            wantedBy    = [ "multi-user.target" ];
            # npm scripts draaien via sh — zorg dat bash + node in PATH zitten
            path        = [ pkgs.bash pkgs.nodejs_20 pkgs.coreutils pkgs.python3 ];
            environment = {
              GATEWAY_URL = "ws://127.0.0.1:18789";
              NODE_ENV    = "production";
            };
            serviceConfig = {
              User             = "agent";
              WorkingDirectory = "/home/agent/workspace/dashboard";
              # Bouw eerst, start daarna — output gaat naar systemd journal
              ExecStartPre     = "${pkgs.nodejs_20}/bin/npm run build";
              ExecStart        = "${pkgs.nodejs_20}/bin/npm run start";
              Restart          = "on-failure";
              RestartSec       = "5s";
              StandardOutput   = "journal";
              StandardError    = "journal";
              SyslogIdentifier = "openclaw-dashboard";
              EnvironmentFile  = "/home/agent/workspace/.env";
            };
          };

          # ── Multi-agent pipeline ────────────────────────────────
          # Writer, researcher en editor worden als interne agents
          # beheerd door de coordinator — geconfigureerd via
          # openclaw.json (agents.list + agents.bindings).
          # Aparte gateway-processen per agent zijn niet nodig.
        })
      ];
    };

    packages.x86_64-linux.default =
      self.nixosConfigurations.openclaw-vm.config.microvm.runner.cloud-hypervisor;
  };
}
