# Development image for the TCB web client (Vite dev server).
FROM node:22-alpine

WORKDIR /app

CMD ["sh", "-c", "[ -d node_modules/.bin ] || npm ci; exec npm run dev -- --host 0.0.0.0"]
