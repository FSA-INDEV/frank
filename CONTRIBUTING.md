# Contributing to FrankenPHP Dev Server & `frank` CLI

Thank you for considering contributing to FrankenPHP Dev Server!

## 🚀 Development Setup

1. Clone the repository:
   ```bash
   git clone https://github.com/<your-username>/frankenphp-dev-server.git
   cd frankenphp-dev-server
   ```
2. Start the development server:
   ```bash
   ./bin/frank start
   ```
3. Run diagnostics:
   ```bash
   ./bin/frank doctor
   ```

## 🧪 Testing

Ensure syntax checks and tests pass:
```bash
bash -n install.sh
bash -n bin/frank
./bin/frank test-mercure
```

## 💡 Submitting Changes
- Open a Pull Request with a clear description of your changes.
- Ensure all CI tests pass.
