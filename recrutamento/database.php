CREATE DATABASE recrutamento;
USE recrutamento;

-- Tabela de Usuários (Recrutadores/Admins)
CREATE TABLE usuarios (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nome VARCHAR(100) NOT NULL,
    email VARCHAR(100) UNIQUE NOT NULL,
    senha VARCHAR(255) NOT NULL, -- Senha criptografada (bcrypt)
    perfil ENUM('admin', 'recrutador') DEFAULT 'recrutador',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Tabela de Vagas
CREATE TABLE vagas (
    id INT AUTO_INCREMENT PRIMARY KEY,
    titulo VARCHAR(150) NOT NULL,
    descricao TEXT NOT NULL,
    requisitos TEXT,
    data_inicio DATE,
    data_fim DATE,
    status ENUM('aberta', 'fechada', 'pausada') DEFAULT 'aberta',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Tabela de Candidatos
CREATE TABLE candidatos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nome VARCHAR(100) NOT NULL,
    email VARCHAR(100) UNIQUE NOT NULL,
    telefone VARCHAR(20),
    endereco VARCHAR(255),
    arquivo_curriculo VARCHAR(255), -- Caminho do arquivo PDF/Doc
    resumo_profissional TEXT,
    termo_lgpd TINYINT(1) NOT NULL, -- 1 = Aceitou
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Tabela de Experiências (1:N)
CREATE TABLE experiencias (
    id INT AUTO_INCREMENT PRIMARY KEY,
    candidato_id INT,
    empresa VARCHAR(100),
    cargo VARCHAR(100),
    inicio DATE,
    fim DATE,
    atual TINYINT(1) DEFAULT 0,
    descricao TEXT,
    FOREIGN KEY (candidato_id) REFERENCES candidatos(id) ON DELETE CASCADE
);

-- Tabela de Aplicações (Candidato x Vaga) - Controla o Status
CREATE TABLE aplicacoes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    vaga_id INT,
    candidato_id INT,
    status ENUM('inscrito', 'pre_selecionado', 'teste', 'entrevista', 'aprovado', 'reprovado', 'standby') DEFAULT 'inscrito',
    nota_teste DECIMAL(5,2),
    data_aplicacao TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (vaga_id) REFERENCES vagas(id),
    FOREIGN KEY (candidato_id) REFERENCES candidatos(id)
);