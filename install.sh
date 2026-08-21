#!/usr/bin/env bash
# ==============================================================================
#  🐘 FrankenPHP Drop-In Dev Server & CLI Installer
#  Quick Install: curl -fsSL https://raw.githubusercontent.com/<repo>/install.sh | bash
# ==============================================================================

set -eo pipefail

BOLD='\033[1m'
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[0;33m'
CYAN='\033[0;36m'
MAGENTA='\033[0;35m'
RESET='\033[0m'

TARGET_DIR="${1:-/var/www}"
REPO_URL="https://github.com/juandavidmm/frankenphp-dev-server.git"

echo -e "${CYAN}${BOLD}"
echo "  ███████╗██████╗  █████╗ ███╗   ██╗██╗  ██╗"
echo "  ██╔════╝██╔══██╗██╔══██╗████╗  ██║██║ ██╔╝"
echo "  █████╗  ██████╔╝███████║██╔██╗ ██║█████╔╝ "
echo "  ██╔══╝  ██╔══██╗██╔══██║██║╚██╗██║██╔═██╗ "
echo "  ██║     ██║  ██║██║  ██║██║ ╚████║██║  ██╗"
echo "  ╚═╝     ╚═╝  ╚═╝╚═╝  ╚═╝╚═╝  ╚═══╝╚═╝  ╚═╝"
echo -e "  ${MAGENTA}Automated Development Server Installer${RESET}\n"

# 1. Dependency Checks
echo -e "${BOLD}1. Checking Prerequisites...${RESET}"
for cmd in docker git curl; do
    if ! command -v "$cmd" &> /dev/null; then
        echo -e "${RED}❌ Missing required command: ${cmd}. Please install it first.${RESET}"
        exit 1
    fi
done

if ! docker compose version &> /dev/null; then
    echo -e "${RED}❌ Docker Compose v2 is required. Please install docker-compose-plugin.${RESET}"
    exit 1
fi
echo -e "${GREEN}✓ All prerequisites met (Docker, Compose, Git, Curl).${RESET}\n"

# 2. Setup Destination Directory
echo -e "${BOLD}2. Setting up installation directory at ${CYAN}${TARGET_DIR}${RESET}..."
if [[ ! -d "$TARGET_DIR" ]]; then
    mkdir -p "$TARGET_DIR"
fi

CURRENT_USER=$(id -un)
CURRENT_UID=$(id -u)
CURRENT_GID=$(id -g)

# If running installer from outside the repo, clone it
if [[ ! -f "${TARGET_DIR}/compose.yaml" ]]; then
    echo -e "   Cloning repository into ${TARGET_DIR}..."
    git clone "${REPO_URL}" "${TARGET_DIR}"
fi

# 3. Environment & Mercure JWT Generation
echo -e "\n${BOLD}3. Configuring Environment & Mercure Secrets...${RESET}"
if [[ ! -f "${TARGET_DIR}/.env" ]]; then
    cp "${TARGET_DIR}/.env.example" "${TARGET_DIR}/.env"
    
    # Generate cryptographically secure JWT secrets
    PUB_SECRET=$(openssl rand -hex 32 2>/dev/null || date +%s%N | sha256sum | head -c 64)
    SUB_SECRET=$(openssl rand -hex 32 2>/dev/null || date +%s%N | sha256sum | head -c 64)
    
    sed -i "s|MERCURE_PUBLISHER_JWT_KEY=.*|MERCURE_PUBLISHER_JWT_KEY=${PUB_SECRET}|g" "${TARGET_DIR}/.env"
    sed -i "s|MERCURE_SUBSCRIBER_JWT_KEY=.*|MERCURE_SUBSCRIBER_JWT_KEY=${SUB_SECRET}|g" "${TARGET_DIR}/.env"
    sed -i "s|PUID=.*|PUID=${CURRENT_UID}|g" "${TARGET_DIR}/.env"
    sed -i "s|PGID=.*|PGID=${CURRENT_GID}|g" "${TARGET_DIR}/.env"
    echo -e "${GREEN}✓ Generated secure random Mercure JWT keys in .env${RESET}"
else
    echo -e "${YELLOW}ℹ Existing .env file found. Preserving current configuration.${RESET}"
fi

# 4. Install Global CLI symlinks
echo -e "\n${BOLD}4. Installing 'frank' CLI tool globally...${RESET}"
chmod +x "${TARGET_DIR}/bin/frank"

# Try installing symlink to /usr/local/bin
if [[ -w "/usr/local/bin" ]]; then
    ln -sf "${TARGET_DIR}/bin/frank" /usr/local/bin/frank
    ln -sf "${TARGET_DIR}/bin/frank" /usr/local/bin/frankenv
    ln -sf "${TARGET_DIR}/bin/frank" /usr/local/bin/fphp
    echo -e "${GREEN}✓ Created global command /usr/local/bin/frank (and aliases: frankenv, fphp)${RESET}"
elif command -v sudo &> /dev/null; then
    sudo ln -sf "${TARGET_DIR}/bin/frank" /usr/local/bin/frank
    sudo ln -sf "${TARGET_DIR}/bin/frank" /usr/local/bin/frankenv
    sudo ln -sf "${TARGET_DIR}/bin/frank" /usr/local/bin/fphp
    echo -e "${GREEN}✓ Created global command /usr/local/bin/frank (via sudo)${RESET}"
else
    echo -e "${YELLOW}⚠️ Could not write to /usr/local/bin. Add ${TARGET_DIR}/bin to your PATH:${RESET}"
    echo -e "   export PATH=\"\$PATH:${TARGET_DIR}/bin\""
fi

# Fix ownership
chown -R "${CURRENT_UID}:${CURRENT_GID}" "${TARGET_DIR}" 2>/dev/null || true

# 5. Build and Launch
echo -e "\n${BOLD}5. Starting FrankenPHP Environment...${RESET}"
cd "${TARGET_DIR}"
docker compose up -d --build

# 6. Completion Summary
echo -e "\n${GREEN}${BOLD}══════════════════════════════════════════════════════════════════════${RESET}"
echo -e "${GREEN}${BOLD}  🎉 FrankenPHP Development Server successfully installed!${RESET}"
echo -e "${GREEN}${BOLD}══════════════════════════════════════════════════════════════════════${RESET}\n"

echo -e "${BOLD}🌐 Web Endpoints:${RESET}"
echo -e "  • Developer Hub:      ${CYAN}http://localhost${RESET}"
echo -e "  • Mercure SSE Hub:    ${CYAN}http://localhost/mercure-demo.html${RESET}"
echo -e "  • Adminer DB GUI:     ${CYAN}http://localhost:8080${RESET}"
echo -e "  • Healthcheck:        ${CYAN}http://localhost/healthz${RESET}\n"

echo -e "${BOLD}🛠️ Useful CLI Commands:${RESET}"
echo -e "  • ${CYAN}frank status${RESET}         Show running containers & projects"
echo -e "  • ${CYAN}frank logs${RESET}           Follow server logs"
echo -e "  • ${CYAN}frank new my-app${RESET}     Create a new project in /var/www"
echo -e "  • ${CYAN}frank doctor${RESET}         Run health check diagnostics"
echo -e "  • ${CYAN}frank reload${RESET}         Reload Caddy configuration without downtime\n"
