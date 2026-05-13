/**
 * =====================================================================
 * SERVIÇO WHATSAPP - IMPÉRIO AR
 * =====================================================================
 * Servidor Node.js com whatsapp-web.js
 * Comunica com PHP via API REST
 * =====================================================================
 */

const express = require('express');
const { Client, LocalAuth, MessageMedia } = require('whatsapp-web.js');
const qrcode = require('qrcode');
const http = require('http');
const socketIo = require('socket.io');
const path = require('path');
const fs = require('fs');
const cors = require('cors');
require('dotenv').config();

const app = express();
const server = http.createServer(app);
const io = socketIo(server, {
    cors: {
        origin: "*",
        methods: ["GET", "POST"]
    }
});

// Middlewares
app.use(cors());
app.use(express.json({ limit: '50mb' }));
app.use(express.urlencoded({ limit: '50mb', extended: true }));
app.use(express.static('public'));

// Configuração
const CONFIG = {
    port: process.env.PORT || 3001,
    sessionDir: path.join(__dirname, 'sessions'),
    logsDir: path.join(__dirname, 'logs'),
    publicDir: path.join(__dirname, 'public')
};

// Criar diretórios necessários
[CONFIG.sessionDir, CONFIG.logsDir].forEach(dir => {
    if (!fs.existsSync(dir)) {
        fs.mkdirSync(dir, { recursive: true });
        console.log(`✓ Diretório criado: ${dir}`);
    }
});

// Armazenar clientes e QR codes
const clients = new Map();
const qrCodes = new Map();
const messageQueue = [];

// ===== LOGGING =====
function log(level, message, data = {}) {
    const timestamp = new Date().toISOString();
    const logMessage = `[${timestamp}] [${level}] ${message}`;
    
    console.log(logMessage, data);
    
    // Salvar em arquivo
    const logFile = path.join(CONFIG.logsDir, `whatsapp-${new Date().toISOString().split('T')[0]}.log`);
    const logEntry = `${logMessage} ${Object.keys(data).length ? JSON.stringify(data) : ''}\n`;
    
    try {
        fs.appendFileSync(logFile, logEntry);
    } catch (err) {
        console.error('Erro ao escrever log:', err);
    }
}

// ===== ROTA: STATUS DO SERVIÇO =====
app.get('/api/status', (req, res) => {
    log('INFO', 'Status solicitado');
    
    const status = {
        service: 'running',
        timestamp: new Date().toISOString(),
        uptime: process.uptime(),
        clients: Array.from(clients.entries()).map(([key, data]) => ({
            sessionId: key,
            ready: data.ready || false,
            phoneNumber: data.phoneNumber || null,
            connectedAt: data.connectedAt || null,
            messageCount: data.messageCount || 0
        })),
        queueSize: messageQueue.length
    };
    
    res.json(status);
});

// ===== ROTA: INICIAR SESSÃO =====
app.post('/api/start', async (req, res) => {
    const { sessionId = 'default' } = req.body;
    
    log('INFO', 'Iniciando sessão', { sessionId });
    
    // Verificar se já existe
    if (clients.has(sessionId)) {
        const existing = clients.get(sessionId);
        if (existing.ready) {
            return res.json({
                success: true,
                message: 'Sessão já está conectada',
                sessionId,
                phoneNumber: existing.phoneNumber
            });
        }
    }

    try {
        // Configurar cliente
        const client = new Client({
            authStrategy: new LocalAuth({
                clientId: sessionId,
                dataPath: path.join(CONFIG.sessionDir, sessionId)
            }),
            puppeteer: {
                headless: true,
                args: [
                    '--no-sandbox',
                    '--disable-setuid-sandbox',
                    '--disable-dev-shm-usage',
                    '--disable-gpu'
                ]
            },
            restartOnAuthFail: true,
            takeoverOnConflict: true
        });

        // Evento: QR Code
        client.on('qr', async (qr) => {
            log('INFO', 'QR Code gerado', { sessionId });
            try {
                const qrImage = await qrcode.toDataURL(qr);
                qrCodes.set(sessionId, qrImage);
                
                io.emit('qr', {
                    sessionId,
                    qr: qrImage,
                    timestamp: new Date().toISOString()
                });
            } catch (err) {
                log('ERROR', 'Erro ao gerar QR Code', { sessionId, error: err.message });
            }
        });

        // Evento: Pronto
        client.on('ready', () => {
            log('INFO', 'Cliente pronto', { sessionId });
            
            const clientInfo = clients.get(sessionId) || {};
            clientInfo.ready = true;
            clientInfo.info = client.info;
            clientInfo.phoneNumber = client.info.me.user;
            clientInfo.connectedAt = new Date().toISOString();
            clientInfo.messageCount = 0;
            clientInfo.client = client;
            
            clients.set(sessionId, clientInfo);
            qrCodes.delete(sessionId);
            
            io.emit('ready', {
                sessionId,
                phoneNumber: client.info.me.user,
                timestamp: new Date().toISOString()
            });
            
            log('INFO', 'Sessão pronta para uso', { sessionId, phone: client.info.me.user });
        });

        // Evento: Falha de autenticação
        client.on('auth_failure', (msg) => {
            log('ERROR', 'Falha de autenticação', { sessionId, message: msg });
            io.emit('auth_failure', { sessionId, message: msg });
        });

        // Evento: Desconectado
        client.on('disconnected', (reason) => {
            log('WARN', 'Cliente desconectado', { sessionId, reason });
            clients.delete(sessionId);
            qrCodes.delete(sessionId);
            io.emit('disconnected', { sessionId, reason });
        });

        // Inicializar
        client.initialize();
        clients.set(sessionId, { ready: false, messageCount: 0 });

        res.json({ 
            success: true, 
            message: 'Sessão iniciada. Escaneie o QR Code.',
            sessionId 
        });
        
    } catch (error) {
        log('ERROR', 'Erro ao iniciar sessão', { sessionId, error: error.message });
        res.status(500).json({ 
            success: false, 
            message: error.message 
        });
    }
});

// ===== ROTA: OBTER QR CODE =====
app.get('/api/qr/:sessionId', (req, res) => {
    const { sessionId } = req.params;
    const qr = qrCodes.get(sessionId);
    
    if (qr) {
        res.json({ success: true, qr });
    } else {
        res.status(404).json({ success: false, message: 'QR Code não disponível' });
    }
});

// ===== ROTA: ENVIAR MENSAGEM =====
app.post('/api/send', async (req, res) => {
    const { to, message, sessionId = 'default' } = req.body;
    
    log('INFO', 'Requisição para enviar mensagem', { sessionId, to });
    
    const clientData = clients.get(sessionId);
    if (!clientData || !clientData.ready) {
        log('WARN', 'Cliente não está pronto', { sessionId });
        return res.status(400).json({ 
            success: false, 
            message: 'Cliente não está pronto para enviar mensagens'
        });
    }

    try {
        // Formatar número
        let number = to.replace(/\D/g, '');
        if (!number.startsWith('55')) {
            number = '55' + number;
        }
        number = `${number}@c.us`;

        const response = await clientData.client.sendMessage(number, message);
        
        // Atualizar contador
        clientData.messageCount = (clientData.messageCount || 0) + 1;
        clients.set(sessionId, clientData);
        
        // Salvar no log
        logMessage(sessionId, to, message, 'sent', response.id.id);
        
        log('INFO', 'Mensagem enviada com sucesso', { sessionId, to, messageId: response.id.id });
        
        res.json({ 
            success: true, 
            message: 'Mensagem enviada',
            messageId: response.id.id,
            timestamp: response.timestamp 
        });
    } catch (error) {
        log('ERROR', 'Erro ao enviar mensagem', { sessionId, to, error: error.message });
        res.status(500).json({ 
            success: false, 
            message: error.message 
        });
    }
});

// ===== ROTA: VERIFICAR NÚMERO =====
app.post('/api/check-number', async (req, res) => {
    const { number, sessionId = 'default' } = req.body;
    
    const clientData = clients.get(sessionId);
    if (!clientData || !clientData.ready) {
        return res.status(400).json({ 
            success: false, 
            message: 'Cliente não está pronto' 
        });
    }

    try {
        let formattedNumber = number.replace(/\D/g, '');
        if (!formattedNumber.startsWith('55')) {
            formattedNumber = '55' + formattedNumber;
        }
        formattedNumber = `${formattedNumber}@c.us`;
        
        const numberDetails = await clientData.client.getNumberId(formattedNumber);
        
        log('INFO', 'Número verificado', { sessionId, number, exists: !!numberDetails });
        
        res.json({ 
            success: true, 
            exists: !!numberDetails,
            details: numberDetails 
        });
    } catch (error) {
        log('ERROR', 'Erro ao verificar número', { sessionId, number, error: error.message });
        res.status(500).json({ 
            success: false, 
            message: error.message 
        });
    }
});

// ===== ROTA: LOGOUT =====
app.post('/api/logout/:sessionId', async (req, res) => {
    const { sessionId } = req.params;
    
    log('INFO', 'Logout solicitado', { sessionId });
    
    const clientData = clients.get(sessionId);
    if (!clientData) {
        return res.status(404).json({ 
            success: false, 
            message: 'Sessão não encontrada' 
        });
    }

    try {
        if (clientData.client) {
            await clientData.client.logout();
        }
        
        clients.delete(sessionId);
        qrCodes.delete(sessionId);
        
        io.emit('logout', { sessionId });
        
        log('INFO', 'Logout realizado', { sessionId });
        
        res.json({ 
            success: true, 
            message: 'Desconectado com sucesso' 
        });
    } catch (error) {
        log('ERROR', 'Erro no logout', { sessionId, error: error.message });
        res.status(500).json({ 
            success: false, 
            message: error.message 
        });
    }
});

// ===== FUNÇÃO: LOG DE MENSAGEM =====
function logMessage(sessionId, to, message, status, messageId) {
    const logDir = path.join(CONFIG.logsDir, 'messages');
    if (!fs.existsSync(logDir)) {
        fs.mkdirSync(logDir, { recursive: true });
    }
    
    const logFile = path.join(logDir, `messages-${new Date().toISOString().split('T')[0]}.log`);
    const logEntry = {
        timestamp: new Date().toISOString(),
        sessionId,
        to,
        message: message.substring(0, 100),
        status,
        messageId
    };
    
    fs.appendFileSync(logFile, JSON.stringify(logEntry) + '\n');
}

// ===== ROTA: PÁGINA DE ADMIN =====
app.get('/admin/whatsapp', (req, res) => {
    res.sendFile(path.join(CONFIG.publicDir, 'admin.html'));
});

// ===== SOCKET.IO =====
io.on('connection', (socket) => {
    log('INFO', 'Cliente WebSocket conectado', { socketId: socket.id });
    
    socket.on('request-qr', (sessionId) => {
        const qr = qrCodes.get(sessionId);
        if (qr) {
            socket.emit('qr', { sessionId, qr });
        }
    });
    
    socket.on('disconnect', () => {
        log('INFO', 'Cliente WebSocket desconectado', { socketId: socket.id });
    });
});

// ===== INICIA SERVIDOR =====
server.listen(CONFIG.port, () => {
    console.log(`
╔════════════════════════════════════════╗
║   SERVIÇO WHATSAPP - IMPÉRIO AR        ║
║   Status: ✓ Rodando                    ║
║   Porta: ${CONFIG.port}                          ║
║   Admin: http://localhost:${CONFIG.port}/admin/whatsapp   ║
╚════════════════════════════════════════╝
    `);
    log('INFO', 'Servidor iniciado', { port: CONFIG.port });
});

// ===== TRATAMENTO DE ERROS =====
process.on('uncaughtException', (error) => {
    log('FATAL', 'Erro não capturado', { error: error.message });
    console.error(error);
});

process.on('unhandledRejection', (reason, promise) => {
    log('FATAL', 'Promise rejection não tratada', { reason });
    console.error('Promise:', promise);
});
