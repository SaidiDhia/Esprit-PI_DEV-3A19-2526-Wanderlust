-- Création des tables pour le système de pricing dynamique

-- Table des règles de pricing dynamique
CREATE TABLE dynamic_pricing_rules (
    id INT AUTO_INCREMENT PRIMARY KEY,
    event_type VARCHAR(50) NOT NULL,
    emotional_floor_percentage DECIMAL(5,2) NOT NULL,
    max_discount_percentage DECIMAL(5,2) NOT NULL,
    time_weight DECIMAL(3,2) NOT NULL,
    occupancy_weight DECIMAL(3,2) NOT NULL,
    popularity_weight DECIMAL(3,2) NOT NULL,
    occupancy_threshold INT NOT NULL,
    reversibility_factor INT NOT NULL,
    is_active TINYINT(1) DEFAULT 1 NOT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME DEFAULT NULL
);

-- Table de l'historique des prix
CREATE TABLE event_price_history (
    id INT AUTO_INCREMENT PRIMARY KEY,
    event_id INT NOT NULL,
    old_price DECIMAL(10,2) NOT NULL,
    new_price DECIMAL(10,2) NOT NULL,
    discount_percentage DECIMAL(5,2) NOT NULL,
    calculation_factors TEXT NOT NULL,
    reason VARCHAR(100) NOT NULL,
    description TEXT DEFAULT NULL,
    is_automatic TINYINT(1) DEFAULT 1 NOT NULL,
    created_at DATETIME NOT NULL,
    applied_at DATETIME DEFAULT NULL,
    FOREIGN KEY (event_id) REFERENCES events (id) ON DELETE CASCADE
);

-- Table des métriques de popularité
CREATE TABLE event_popularity_metrics (
    id INT AUTO_INCREMENT PRIMARY KEY,
    event_id INT NOT NULL,
    date DATE NOT NULL,
    views_count INT NOT NULL,
    cart_abandonments INT NOT NULL,
    social_shares INT NOT NULL,
    search_mentions INT NOT NULL,
    reservations_count INT NOT NULL,
    average_time_on_page INT NOT NULL,
    calculated_score DECIMAL(5,2) NOT NULL,
    raw_metrics TEXT NOT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME DEFAULT NULL,
    FOREIGN KEY (event_id) REFERENCES events (id) ON DELETE CASCADE
);

-- Insertion d'une règle par défaut
INSERT INTO dynamic_pricing_rules (event_type, emotional_floor_percentage, max_discount_percentage, time_weight, occupancy_weight, popularity_weight, occupancy_threshold, reversibility_factor, is_active, created_at) 
VALUES ('default', 0.50, 0.40, 0.40, 0.35, 0.25, 70, 3, 1, NOW());

-- Insertion d'une règle pour les concerts
INSERT INTO dynamic_pricing_rules (event_type, emotional_floor_percentage, max_discount_percentage, time_weight, occupancy_weight, popularity_weight, occupancy_threshold, reversibility_factor, is_active, created_at) 
VALUES ('concert', 0.60, 0.35, 0.45, 0.30, 0.25, 75, 2, 1, NOW());

-- Insertion d'une règle pour les festivals
INSERT INTO dynamic_pricing_rules (event_type, emotional_floor_percentage, max_discount_percentage, time_weight, occupancy_weight, popularity_weight, occupancy_threshold, reversibility_factor, is_active, created_at) 
VALUES ('festival', 0.40, 0.45, 0.35, 0.40, 0.25, 65, 4, 1, NOW());
