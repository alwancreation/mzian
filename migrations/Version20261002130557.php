<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261002130557 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Initial Mzian schema: users, catalog, requirements, quotes, orders, billing, projects, agents, providers, testing, notifications, audit.';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql(<<<'SQL'
            CREATE TABLE admin_profile (
              id INT AUTO_INCREMENT NOT NULL,
              display_name VARCHAR(120) NOT NULL,
              job_title VARCHAR(120) DEFAULT NULL,
              receives_approval_notifications TINYINT NOT NULL,
              created_at DATETIME NOT NULL,
              user_id INT NOT NULL,
              UNIQUE INDEX UNIQ_456B2886A76ED395 (user_id),
              PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE agent (
              id INT AUTO_INCREMENT NOT NULL,
              code VARCHAR(40) NOT NULL,
              name VARCHAR(120) NOT NULL,
              description VARCHAR(500) NOT NULL,
              enabled TINYINT NOT NULL,
              permissions JSON NOT NULL,
              max_attempts INT NOT NULL,
              created_at DATETIME NOT NULL,
              updated_at DATETIME NOT NULL,
              UNIQUE INDEX UNIQ_268B9C9D77153098 (code),
              PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE agent_run (
              id INT AUTO_INCREMENT NOT NULL,
              agent_code VARCHAR(40) NOT NULL,
              attempt INT NOT NULL,
              status VARCHAR(20) NOT NULL,
              logs JSON NOT NULL,
              error LONGTEXT DEFAULT NULL,
              cost INT NOT NULL,
              tokens_input INT NOT NULL,
              tokens_output INT NOT NULL,
              started_at DATETIME NOT NULL,
              finished_at DATETIME DEFAULT NULL,
              duration_ms INT DEFAULT NULL,
              project_id INT DEFAULT NULL,
              project_task_id INT DEFAULT NULL,
              INDEX idx_agent_run_started (started_at),
              INDEX idx_agent_run_status (status),
              INDEX IDX_AC4401AA166D1F9C (project_id),
              INDEX IDX_AC4401AA1BA80DE3 (project_task_id),
              PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE agent_task (
              id INT AUTO_INCREMENT NOT NULL,
              operation VARCHAR(80) NOT NULL,
              idempotency_key VARCHAR(191) NOT NULL,
              status VARCHAR(12) NOT NULL,
              attempts INT NOT NULL,
              input JSON NOT NULL,
              output JSON NOT NULL,
              error LONGTEXT DEFAULT NULL,
              started_at DATETIME NOT NULL,
              finished_at DATETIME DEFAULT NULL,
              run_id INT NOT NULL,
              project_id INT NOT NULL,
              UNIQUE INDEX UNIQ_FFE561C67FD1C147 (idempotency_key),
              INDEX IDX_FFE561C684E3FEC4 (run_id),
              INDEX IDX_FFE561C6166D1F9C (project_id),
              PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE api_token (
              id INT AUTO_INCREMENT NOT NULL,
              name VARCHAR(100) NOT NULL,
              token_hash VARCHAR(64) NOT NULL,
              token_prefix VARCHAR(16) NOT NULL,
              expires_at DATETIME DEFAULT NULL,
              last_used_at DATETIME DEFAULT NULL,
              revoked_at DATETIME DEFAULT NULL,
              created_at DATETIME NOT NULL,
              user_id INT NOT NULL,
              UNIQUE INDEX UNIQ_7BA2F5EBB3BC57DA (token_hash),
              INDEX IDX_7BA2F5EBA76ED395 (user_id),
              PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE app_user (
              id INT AUTO_INCREMENT NOT NULL,
              email VARCHAR(180) NOT NULL,
              roles JSON NOT NULL,
              password VARCHAR(255) NOT NULL,
              first_name VARCHAR(80) NOT NULL,
              last_name VARCHAR(80) DEFAULT NULL,
              locale VARCHAR(5) NOT NULL,
              active TINYINT NOT NULL,
              last_login_at DATETIME DEFAULT NULL,
              created_at DATETIME NOT NULL,
              updated_at DATETIME NOT NULL,
              UNIQUE INDEX UNIQ_88BDF3E9E7927C74 (email),
              PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE audit_log (
              id INT AUTO_INCREMENT NOT NULL,
              actor_type VARCHAR(20) NOT NULL,
              actor_id VARCHAR(64) DEFAULT NULL,
              actor_name VARCHAR(120) NOT NULL,
              action VARCHAR(100) NOT NULL,
              entity_type VARCHAR(80) DEFAULT NULL,
              entity_id VARCHAR(64) DEFAULT NULL,
              old_value JSON DEFAULT NULL,
              new_value JSON DEFAULT NULL,
              ip VARCHAR(45) DEFAULT NULL,
              user_agent VARCHAR(255) DEFAULT NULL,
              metadata JSON NOT NULL,
              message VARCHAR(255) DEFAULT NULL,
              created_at DATETIME NOT NULL,
              INDEX idx_audit_entity (entity_type, entity_id),
              INDEX idx_audit_action (action),
              INDEX idx_audit_created (created_at),
              PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE conversation (
              id INT AUTO_INCREMENT NOT NULL,
              token VARCHAR(32) NOT NULL,
              sector VARCHAR(60) DEFAULT NULL,
              locale VARCHAR(5) NOT NULL,
              status VARCHAR(10) NOT NULL,
              context JSON NOT NULL,
              created_at DATETIME NOT NULL,
              updated_at DATETIME NOT NULL,
              lead_id INT DEFAULT NULL,
              customer_id INT DEFAULT NULL,
              UNIQUE INDEX UNIQ_8A8E26E95F37A13B (token),
              INDEX IDX_8A8E26E955458D (lead_id),
              INDEX IDX_8A8E26E99395C3F3 (customer_id),
              PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE conversation_message (
              id INT AUTO_INCREMENT NOT NULL,
              role VARCHAR(12) NOT NULL,
              content LONGTEXT NOT NULL,
              metadata JSON NOT NULL,
              created_at DATETIME NOT NULL,
              conversation_id INT NOT NULL,
              INDEX IDX_2DEB3E759AC0396 (conversation_id),
              PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE customer (
              id INT AUTO_INCREMENT NOT NULL,
              company_name VARCHAR(160) DEFAULT NULL,
              phone VARCHAR(40) DEFAULT NULL,
              country VARCHAR(2) DEFAULT NULL,
              city VARCHAR(120) DEFAULT NULL,
              sector VARCHAR(60) DEFAULT NULL,
              created_at DATETIME NOT NULL,
              updated_at DATETIME NOT NULL,
              user_id INT NOT NULL,
              UNIQUE INDEX UNIQ_81398E09A76ED395 (user_id),
              PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE customer_order (
              id INT AUTO_INCREMENT NOT NULL,
              number VARCHAR(24) NOT NULL,
              status VARCHAR(20) NOT NULL,
              currency VARCHAR(3) NOT NULL,
              total INT NOT NULL,
              cost_price INT NOT NULL,
              margin INT NOT NULL,
              recurring_monthly INT NOT NULL,
              paid_at DATETIME DEFAULT NULL,
              cancelled_at DATETIME DEFAULT NULL,
              created_at DATETIME NOT NULL,
              updated_at DATETIME NOT NULL,
              customer_id INT NOT NULL,
              project_id INT NOT NULL,
              quote_id INT NOT NULL,
              UNIQUE INDEX UNIQ_3B1CE6A396901F54 (number),
              UNIQUE INDEX UNIQ_3B1CE6A3166D1F9C (project_id),
              INDEX idx_order_status (status),
              INDEX idx_order_created (created_at),
              INDEX IDX_3B1CE6A39395C3F3 (customer_id),
              INDEX IDX_3B1CE6A3DB805178 (quote_id),
              PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE deployment (
              id INT AUTO_INCREMENT NOT NULL,
              provider VARCHAR(60) NOT NULL,
              environment VARCHAR(20) NOT NULL,
              version VARCHAR(40) NOT NULL,
              commit_sha VARCHAR(64) DEFAULT NULL,
              status VARCHAR(20) NOT NULL,
              url VARCHAR(255) DEFAULT NULL,
              internal_url VARCHAR(255) DEFAULT NULL,
              external_id VARCHAR(120) DEFAULT NULL,
              idempotency_key VARCHAR(160) NOT NULL,
              simulated TINYINT NOT NULL,
              logs JSON NOT NULL,
              error LONGTEXT DEFAULT NULL,
              created_at DATETIME NOT NULL,
              finished_at DATETIME DEFAULT NULL,
              project_id INT NOT NULL,
              UNIQUE INDEX UNIQ_EB1255BE7FD1C147 (idempotency_key),
              INDEX idx_deployment_status (status),
              INDEX IDX_EB1255BE166D1F9C (project_id),
              PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE domain (
              id INT AUTO_INCREMENT NOT NULL,
              name VARCHAR(253) NOT NULL,
              provider VARCHAR(60) NOT NULL,
              status VARCHAR(12) NOT NULL,
              external_id VARCHAR(120) DEFAULT NULL,
              cost INT NOT NULL,
              currency VARCHAR(3) NOT NULL,
              auto_renew TINYINT NOT NULL,
              simulated TINYINT NOT NULL,
              nameservers JSON NOT NULL,
              dns_records JSON NOT NULL,
              registered_at DATETIME DEFAULT NULL,
              expires_at DATETIME DEFAULT NULL,
              created_at DATETIME NOT NULL,
              updated_at DATETIME NOT NULL,
              project_id INT DEFAULT NULL,
              UNIQUE INDEX UNIQ_A7A91E0B5E237E06 (name),
              INDEX IDX_A7A91E0B166D1F9C (project_id),
              PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE hosting_account (
              id INT AUTO_INCREMENT NOT NULL,
              provider VARCHAR(60) NOT NULL,
              external_id VARCHAR(120) NOT NULL,
              status VARCHAR(20) NOT NULL,
              username VARCHAR(120) DEFAULT NULL,
              region VARCHAR(60) DEFAULT NULL,
              ip_address VARCHAR(45) DEFAULT NULL,
              control_panel_url VARCHAR(255) DEFAULT NULL,
              cost INT NOT NULL,
              currency VARCHAR(3) NOT NULL,
              idempotency_key VARCHAR(160) NOT NULL,
              simulated TINYINT NOT NULL,
              expires_at DATETIME DEFAULT NULL,
              metadata JSON NOT NULL,
              created_at DATETIME NOT NULL,
              updated_at DATETIME NOT NULL,
              project_id INT NOT NULL,
              plan_id INT DEFAULT NULL,
              UNIQUE INDEX UNIQ_10B6033E7FD1C147 (idempotency_key),
              INDEX IDX_10B6033E166D1F9C (project_id),
              INDEX IDX_10B6033EE899029B (plan_id),
              PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE hosting_deployment (
              id INT AUTO_INCREMENT NOT NULL,
              external_id VARCHAR(120) NOT NULL,
              document_root VARCHAR(255) NOT NULL,
              php_version VARCHAR(10) DEFAULT NULL,
              database_name VARCHAR(64) DEFAULT NULL,
              ssl_enabled TINYINT NOT NULL,
              status VARCHAR(20) NOT NULL,
              idempotency_key VARCHAR(160) NOT NULL,
              metadata JSON NOT NULL,
              created_at DATETIME NOT NULL,
              updated_at DATETIME NOT NULL,
              hosting_account_id INT NOT NULL,
              project_id INT NOT NULL,
              UNIQUE INDEX UNIQ_31E242C47FD1C147 (idempotency_key),
              INDEX IDX_31E242C4419B48C0 (hosting_account_id),
              INDEX IDX_31E242C4166D1F9C (project_id),
              PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE hosting_plan (
              id INT AUTO_INCREMENT NOT NULL,
              code VARCHAR(60) NOT NULL,
              name VARCHAR(120) NOT NULL,
              price INT NOT NULL,
              currency VARCHAR(3) NOT NULL,
              billing_period VARCHAR(10) NOT NULL,
              specs JSON NOT NULL,
              capabilities JSON NOT NULL,
              enabled TINYINT NOT NULL,
              provider_id INT NOT NULL,
              UNIQUE INDEX uniq_hosting_plan_code (provider_id, code),
              INDEX IDX_61177D94A53A8AA (provider_id),
              PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE invoice (
              id INT AUTO_INCREMENT NOT NULL,
              number VARCHAR(24) NOT NULL,
              status VARCHAR(10) NOT NULL,
              currency VARCHAR(3) NOT NULL,
              subtotal INT NOT NULL,
              tax_amount INT NOT NULL,
              total INT NOT NULL,
              invoice_lines JSON NOT NULL,
              billing_details JSON NOT NULL,
              issued_at DATETIME NOT NULL,
              paid_at DATETIME DEFAULT NULL,
              order_id INT DEFAULT NULL,
              subscription_id INT DEFAULT NULL,
              customer_id INT NOT NULL,
              UNIQUE INDEX UNIQ_9065174496901F54 (number),
              INDEX IDX_906517448D9F6D38 (order_id),
              INDEX IDX_906517449A1887DC (subscription_id),
              INDEX IDX_906517449395C3F3 (customer_id),
              PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE lead_activity (
              id INT AUTO_INCREMENT NOT NULL,
              type VARCHAR(50) NOT NULL,
              description VARCHAR(255) NOT NULL,
              metadata JSON NOT NULL,
              created_at DATETIME NOT NULL,
              lead_id INT NOT NULL,
              INDEX idx_lead_activity_type (type),
              INDEX IDX_6278B5955458D (lead_id),
              PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE mock_provider_resource (
              id INT AUTO_INCREMENT NOT NULL,
              provider VARCHAR(40) NOT NULL,
              kind VARCHAR(40) NOT NULL,
              idempotency_key VARCHAR(160) NOT NULL,
              external_id VARCHAR(64) NOT NULL,
              payload JSON NOT NULL,
              created_at DATETIME NOT NULL,
              UNIQUE INDEX uniq_mock_resource_key (provider, kind, idempotency_key),
              PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE notification (
              id INT AUTO_INCREMENT NOT NULL,
              recipient_email VARCHAR(180) NOT NULL,
              type VARCHAR(40) NOT NULL,
              channel VARCHAR(20) NOT NULL,
              subject VARCHAR(255) NOT NULL,
              body LONGTEXT NOT NULL,
              context JSON NOT NULL,
              status VARCHAR(10) NOT NULL,
              error LONGTEXT DEFAULT NULL,
              created_at DATETIME NOT NULL,
              sent_at DATETIME DEFAULT NULL,
              read_at DATETIME DEFAULT NULL,
              recipient_id INT DEFAULT NULL,
              project_id INT DEFAULT NULL,
              INDEX idx_notification_status (status),
              INDEX IDX_BF5476CAE92F8F78 (recipient_id),
              INDEX IDX_BF5476CA166D1F9C (project_id),
              PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE order_item (
              id INT AUTO_INCREMENT NOT NULL,
              code VARCHAR(80) NOT NULL,
              label VARCHAR(255) NOT NULL,
              type VARCHAR(20) NOT NULL,
              unit_price INT NOT NULL,
              quantity INT NOT NULL,
              recurring TINYINT NOT NULL,
              order_id INT NOT NULL,
              INDEX IDX_52EA1F098D9F6D38 (order_id),
              PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE payment (
              id INT AUTO_INCREMENT NOT NULL,
              provider VARCHAR(40) NOT NULL,
              provider_reference VARCHAR(191) DEFAULT NULL,
              idempotency_key VARCHAR(120) NOT NULL,
              amount INT NOT NULL,
              currency VARCHAR(3) NOT NULL,
              status VARCHAR(20) NOT NULL,
              method VARCHAR(30) DEFAULT NULL,
              checkout_url VARCHAR(500) DEFAULT NULL,
              failure_reason VARCHAR(500) DEFAULT NULL,
              metadata JSON NOT NULL,
              paid_at DATETIME DEFAULT NULL,
              created_at DATETIME NOT NULL,
              updated_at DATETIME NOT NULL,
              order_id INT NOT NULL,
              UNIQUE INDEX UNIQ_6D28840D7FD1C147 (idempotency_key),
              INDEX idx_payment_status (status),
              UNIQUE INDEX uniq_payment_provider_ref (provider, provider_reference),
              INDEX IDX_6D28840D8D9F6D38 (order_id),
              PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE project (
              id INT AUTO_INCREMENT NOT NULL,
              reference VARCHAR(24) NOT NULL,
              slug VARCHAR(80) NOT NULL,
              name VARCHAR(160) NOT NULL,
              status VARCHAR(40) NOT NULL,
              business_name VARCHAR(160) DEFAULT NULL,
              sector VARCHAR(60) DEFAULT NULL,
              locale VARCHAR(5) NOT NULL,
              features JSON NOT NULL,
              application_template VARCHAR(60) DEFAULT NULL,
              complexity VARCHAR(10) DEFAULT NULL,
              estimated_development_days INT DEFAULT NULL,
              risk VARCHAR(10) NOT NULL,
              risk_notes JSON NOT NULL,
              domain_name VARCHAR(253) DEFAULT NULL,
              budget INT NOT NULL,
              currency VARCHAR(3) NOT NULL,
              automation_paused TINYINT NOT NULL,
              hold_reason LONGTEXT DEFAULT NULL,
              resume_status VARCHAR(40) DEFAULT NULL,
              spending_overrides JSON NOT NULL,
              provider_overrides JSON NOT NULL,
              simulated TINYINT NOT NULL,
              repository_url VARCHAR(255) DEFAULT NULL,
              deployment_url VARCHAR(255) DEFAULT NULL,
              admin_url VARCHAR(255) DEFAULT NULL,
              delivery_documentation LONGTEXT DEFAULT NULL,
              admin_notes LONGTEXT DEFAULT NULL,
              changes_requested LONGTEXT DEFAULT NULL,
              rejection_reason LONGTEXT DEFAULT NULL,
              approved_at DATETIME DEFAULT NULL,
              delivered_at DATETIME DEFAULT NULL,
              completed_at DATETIME DEFAULT NULL,
              created_at DATETIME NOT NULL,
              updated_at DATETIME NOT NULL,
              lead_id INT DEFAULT NULL,
              customer_id INT DEFAULT NULL,
              requirement_id INT NOT NULL,
              solution_id INT DEFAULT NULL,
              current_quote_id INT DEFAULT NULL,
              approved_by_id INT DEFAULT NULL,
              UNIQUE INDEX UNIQ_2FB3D0EEAEA34913 (reference),
              UNIQUE INDEX UNIQ_2FB3D0EE989D9B62 (slug),
              UNIQUE INDEX UNIQ_2FB3D0EE7B576F77 (requirement_id),
              INDEX idx_project_status (status),
              INDEX idx_project_created (created_at),
              INDEX IDX_2FB3D0EE55458D (lead_id),
              INDEX IDX_2FB3D0EE9395C3F3 (customer_id),
              INDEX IDX_2FB3D0EE1C0BE183 (solution_id),
              INDEX IDX_2FB3D0EE46F82B52 (current_quote_id),
              INDEX IDX_2FB3D0EE2D234F6A (approved_by_id),
              PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE project_cost_entry (
              id INT AUTO_INCREMENT NOT NULL,
              category VARCHAR(20) NOT NULL,
              amount INT NOT NULL,
              currency VARCHAR(3) NOT NULL,
              description VARCHAR(255) NOT NULL,
              reference VARCHAR(120) DEFAULT NULL,
              idempotency_key VARCHAR(160) DEFAULT NULL,
              simulated TINYINT NOT NULL,
              created_at DATETIME NOT NULL,
              project_id INT NOT NULL,
              UNIQUE INDEX UNIQ_680550467FD1C147 (idempotency_key),
              INDEX idx_cost_category (category),
              INDEX IDX_68055046166D1F9C (project_id),
              PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE project_credential (
              id INT AUTO_INCREMENT NOT NULL,
              type VARCHAR(30) NOT NULL,
              label VARCHAR(120) NOT NULL,
              username VARCHAR(180) NOT NULL,
              encrypted_secret LONGTEXT NOT NULL,
              url VARCHAR(255) DEFAULT NULL,
              reveal_count INT NOT NULL,
              last_revealed_at DATETIME DEFAULT NULL,
              created_at DATETIME NOT NULL,
              project_id INT NOT NULL,
              INDEX IDX_1DD96679166D1F9C (project_id),
              PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE project_event (
              id INT AUTO_INCREMENT NOT NULL,
              type VARCHAR(20) NOT NULL,
              transition VARCHAR(60) DEFAULT NULL,
              from_status VARCHAR(40) DEFAULT NULL,
              to_status VARCHAR(40) DEFAULT NULL,
              actor_type VARCHAR(20) NOT NULL,
              actor_name VARCHAR(120) NOT NULL,
              message VARCHAR(500) NOT NULL,
              metadata JSON NOT NULL,
              created_at DATETIME NOT NULL,
              project_id INT NOT NULL,
              INDEX idx_project_event_created (created_at),
              INDEX IDX_28FB0339166D1F9C (project_id),
              PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE project_task (
              id INT AUTO_INCREMENT NOT NULL,
              step VARCHAR(20) NOT NULL,
              agent_code VARCHAR(40) NOT NULL,
              status VARCHAR(20) NOT NULL,
              attempts INT NOT NULL,
              max_attempts INT NOT NULL,
              position INT NOT NULL,
              cost INT NOT NULL,
              output JSON NOT NULL,
              last_error LONGTEXT DEFAULT NULL,
              started_at DATETIME DEFAULT NULL,
              finished_at DATETIME DEFAULT NULL,
              created_at DATETIME NOT NULL,
              updated_at DATETIME NOT NULL,
              project_id INT NOT NULL,
              INDEX idx_project_task_status (status),
              UNIQUE INDEX uniq_project_task_step (project_id, step),
              INDEX IDX_6BEF133D166D1F9C (project_id),
              PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE provider (
              id INT AUTO_INCREMENT NOT NULL,
              code VARCHAR(60) NOT NULL,
              type VARCHAR(20) NOT NULL,
              driver VARCHAR(40) NOT NULL,
              name VARCHAR(120) NOT NULL,
              enabled TINYINT NOT NULL,
              is_default TINYINT NOT NULL,
              priority INT NOT NULL,
              provisioning_method VARCHAR(10) NOT NULL,
              settings JSON NOT NULL,
              capabilities JSON NOT NULL,
              created_at DATETIME NOT NULL,
              updated_at DATETIME NOT NULL,
              UNIQUE INDEX UNIQ_92C4739C77153098 (code),
              INDEX idx_provider_type (type, enabled),
              PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE provider_credential (
              id INT AUTO_INCREMENT NOT NULL,
              name VARCHAR(60) NOT NULL,
              encrypted_value LONGTEXT NOT NULL,
              created_at DATETIME NOT NULL,
              rotated_at DATETIME NOT NULL,
              last_used_at DATETIME DEFAULT NULL,
              provider_id INT NOT NULL,
              UNIQUE INDEX uniq_provider_credential_name (provider_id, name),
              INDEX IDX_20C951F3A53A8AA (provider_id),
              PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE question (
              id INT AUTO_INCREMENT NOT NULL,
              code VARCHAR(80) NOT NULL,
              type VARCHAR(20) NOT NULL,
              label JSON NOT NULL,
              help JSON NOT NULL,
              options JSON NOT NULL,
              condition_expression VARCHAR(500) DEFAULT NULL,
              position INT NOT NULL,
              required TINYINT NOT NULL,
              enabled TINYINT NOT NULL,
              sector_id INT DEFAULT NULL,
              INDEX idx_question_position (position),
              UNIQUE INDEX uniq_question_sector_code (sector_id, code),
              INDEX IDX_B6F7494EDE95C867 (sector_id),
              PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE quote (
              id INT AUTO_INCREMENT NOT NULL,
              number VARCHAR(24) NOT NULL,
              token VARCHAR(32) NOT NULL,
              status VARCHAR(12) NOT NULL,
              currency VARCHAR(3) NOT NULL,
              cost_price INT NOT NULL,
              selling_price INT NOT NULL,
              margin INT NOT NULL,
              margin_percentage DOUBLE PRECISION NOT NULL,
              recurring_monthly INT NOT NULL,
              features JSON NOT NULL,
              domain_name VARCHAR(253) DEFAULT NULL,
              pricing_snapshot JSON NOT NULL,
              valid_until DATETIME NOT NULL,
              created_at DATETIME NOT NULL,
              accepted_at DATETIME DEFAULT NULL,
              project_id INT NOT NULL,
              requirement_id INT NOT NULL,
              solution_id INT NOT NULL,
              hosting_plan_id INT DEFAULT NULL,
              subscription_plan_id INT DEFAULT NULL,
              UNIQUE INDEX UNIQ_6B71CBF496901F54 (number),
              UNIQUE INDEX UNIQ_6B71CBF45F37A13B (token),
              INDEX idx_quote_status (status),
              INDEX IDX_6B71CBF4166D1F9C (project_id),
              INDEX IDX_6B71CBF47B576F77 (requirement_id),
              INDEX IDX_6B71CBF41C0BE183 (solution_id),
              INDEX IDX_6B71CBF485195701 (hosting_plan_id),
              INDEX IDX_6B71CBF49B8CE200 (subscription_plan_id),
              PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE quote_item (
              id INT AUTO_INCREMENT NOT NULL,
              code VARCHAR(80) NOT NULL,
              label VARCHAR(255) NOT NULL,
              type VARCHAR(20) NOT NULL,
              cost INT NOT NULL,
              price INT NOT NULL,
              recurring TINYINT NOT NULL,
              customer_visible TINYINT NOT NULL,
              position INT NOT NULL,
              quote_id INT NOT NULL,
              INDEX IDX_8DFC7A94DB805178 (quote_id),
              PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE requirement (
              id INT AUTO_INCREMENT NOT NULL,
              token VARCHAR(32) NOT NULL,
              sector VARCHAR(60) DEFAULT NULL,
              business_name VARCHAR(160) DEFAULT NULL,
              city VARCHAR(120) DEFAULT NULL,
              description LONGTEXT DEFAULT NULL,
              answers JSON NOT NULL,
              locale VARCHAR(5) NOT NULL,
              status VARCHAR(12) NOT NULL,
              analysis JSON DEFAULT NULL,
              analyzed_at DATETIME DEFAULT NULL,
              created_at DATETIME NOT NULL,
              updated_at DATETIME NOT NULL,
              lead_id INT DEFAULT NULL,
              customer_id INT DEFAULT NULL,
              conversation_id INT DEFAULT NULL,
              recommended_solution_id INT DEFAULT NULL,
              UNIQUE INDEX UNIQ_DB3F55505F37A13B (token),
              UNIQUE INDEX UNIQ_DB3F55509AC0396 (conversation_id),
              INDEX idx_requirement_status (status),
              INDEX IDX_DB3F555055458D (lead_id),
              INDEX IDX_DB3F55509395C3F3 (customer_id),
              INDEX IDX_DB3F55503AC56173 (recommended_solution_id),
              PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE requirement_item (
              id INT AUTO_INCREMENT NOT NULL,
              item_key VARCHAR(80) NOT NULL,
              label VARCHAR(255) NOT NULL,
              item_value JSON NOT NULL,
              source VARCHAR(20) NOT NULL,
              confidence DOUBLE PRECISION DEFAULT NULL,
              updated_at DATETIME NOT NULL,
              requirement_id INT NOT NULL,
              UNIQUE INDEX uniq_requirement_item_key (requirement_id, item_key),
              INDEX IDX_CB05DB277B576F77 (requirement_id),
              PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE sales_lead (
              id INT AUTO_INCREMENT NOT NULL,
              email VARCHAR(180) NOT NULL,
              full_name VARCHAR(120) NOT NULL,
              phone VARCHAR(40) DEFAULT NULL,
              company_name VARCHAR(160) DEFAULT NULL,
              sector VARCHAR(60) DEFAULT NULL,
              city VARCHAR(120) DEFAULT NULL,
              source VARCHAR(20) NOT NULL,
              status VARCHAR(20) NOT NULL,
              utm JSON NOT NULL,
              referrer VARCHAR(255) DEFAULT NULL,
              landing_page VARCHAR(255) DEFAULT NULL,
              locale VARCHAR(5) NOT NULL,
              marketing_consent TINYINT NOT NULL,
              created_at DATETIME NOT NULL,
              updated_at DATETIME NOT NULL,
              customer_id INT DEFAULT NULL,
              INDEX idx_lead_email (email),
              INDEX idx_lead_status (status),
              INDEX idx_lead_source (source),
              INDEX idx_lead_created (created_at),
              INDEX IDX_D5068C1E9395C3F3 (customer_id),
              PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE sector (
              id INT AUTO_INCREMENT NOT NULL,
              code VARCHAR(60) NOT NULL,
              name JSON NOT NULL,
              description JSON NOT NULL,
              slugs JSON NOT NULL,
              icon VARCHAR(16) NOT NULL,
              default_solution_code VARCHAR(80) DEFAULT NULL,
              position INT NOT NULL,
              enabled TINYINT NOT NULL,
              UNIQUE INDEX UNIQ_4BA3D9E877153098 (code),
              PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE setting (
              id INT AUTO_INCREMENT NOT NULL,
              setting_key VARCHAR(100) NOT NULL,
              value JSON NOT NULL,
              updated_at DATETIME NOT NULL,
              updated_by VARCHAR(120) DEFAULT NULL,
              UNIQUE INDEX UNIQ_9F74B8985FA1E697 (setting_key),
              PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE solution (
              id INT AUTO_INCREMENT NOT NULL,
              code VARCHAR(80) NOT NULL,
              slug VARCHAR(120) NOT NULL,
              slugs JSON NOT NULL,
              name JSON NOT NULL,
              short_description JSON NOT NULL,
              description JSON NOT NULL,
              category VARCHAR(20) NOT NULL,
              base_price INT NOT NULL,
              estimated_development_days INT NOT NULL,
              maintenance_price INT NOT NULL,
              hosting_requirements JSON NOT NULL,
              domain_requirements JSON NOT NULL,
              application_template VARCHAR(60) NOT NULL,
              sectors JSON NOT NULL,
              icon VARCHAR(16) NOT NULL,
              enabled TINYINT NOT NULL,
              featured TINYINT NOT NULL,
              position INT NOT NULL,
              created_at DATETIME NOT NULL,
              updated_at DATETIME NOT NULL,
              UNIQUE INDEX UNIQ_9F3329DB77153098 (code),
              UNIQUE INDEX UNIQ_9F3329DB989D9B62 (slug),
              INDEX idx_solution_enabled (enabled, position),
              PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE solution_feature (
              id INT AUTO_INCREMENT NOT NULL,
              code VARCHAR(80) NOT NULL,
              name JSON NOT NULL,
              description JSON NOT NULL,
              included TINYINT NOT NULL,
              price INT NOT NULL,
              complexity_weight INT NOT NULL,
              enabled TINYINT NOT NULL,
              position INT NOT NULL,
              solution_id INT NOT NULL,
              UNIQUE INDEX uniq_solution_feature_code (solution_id, code),
              INDEX IDX_BB7547DC1C0BE183 (solution_id),
              PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE subscription (
              id INT AUTO_INCREMENT NOT NULL,
              status VARCHAR(12) NOT NULL,
              price INT NOT NULL,
              currency VARCHAR(3) NOT NULL,
              billing_interval VARCHAR(10) NOT NULL,
              provider VARCHAR(40) NOT NULL,
              provider_reference VARCHAR(191) DEFAULT NULL,
              current_period_start DATETIME DEFAULT NULL,
              current_period_end DATETIME DEFAULT NULL,
              cancelled_at DATETIME DEFAULT NULL,
              created_at DATETIME NOT NULL,
              updated_at DATETIME NOT NULL,
              customer_id INT NOT NULL,
              project_id INT DEFAULT NULL,
              plan_id INT NOT NULL,
              INDEX idx_subscription_status (status),
              INDEX IDX_A3C664D39395C3F3 (customer_id),
              INDEX IDX_A3C664D3166D1F9C (project_id),
              INDEX IDX_A3C664D3E899029B (plan_id),
              PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE subscription_plan (
              id INT AUTO_INCREMENT NOT NULL,
              code VARCHAR(60) NOT NULL,
              name JSON NOT NULL,
              description JSON NOT NULL,
              price INT NOT NULL,
              billing_interval VARCHAR(10) NOT NULL,
              features JSON NOT NULL,
              includes JSON NOT NULL,
              recommended TINYINT NOT NULL,
              enabled TINYINT NOT NULL,
              position INT NOT NULL,
              UNIQUE INDEX UNIQ_EA664B6377153098 (code),
              PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE test_result (
              id INT AUTO_INCREMENT NOT NULL,
              code VARCHAR(80) NOT NULL,
              name VARCHAR(160) NOT NULL,
              category VARCHAR(20) NOT NULL,
              severity VARCHAR(10) NOT NULL,
              status VARCHAR(10) NOT NULL,
              message VARCHAR(1000) NOT NULL,
              duration_ms INT NOT NULL,
              details JSON NOT NULL,
              test_run_id INT NOT NULL,
              INDEX IDX_84B3C63D133AF9EA (test_run_id),
              PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE test_run (
              id INT AUTO_INCREMENT NOT NULL,
              type VARCHAR(12) NOT NULL,
              status VARCHAR(10) NOT NULL,
              score INT DEFAULT NULL,
              target VARCHAR(255) DEFAULT NULL,
              started_at DATETIME NOT NULL,
              finished_at DATETIME DEFAULT NULL,
              project_id INT NOT NULL,
              INDEX IDX_290B7807166D1F9C (project_id),
              PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE visit (
              id INT AUTO_INCREMENT NOT NULL,
              visitor_hash VARCHAR(64) NOT NULL,
              day DATE NOT NULL,
              landing_path VARCHAR(255) NOT NULL,
              source VARCHAR(20) NOT NULL,
              referrer_host VARCHAR(120) DEFAULT NULL,
              locale VARCHAR(5) NOT NULL,
              created_at DATETIME NOT NULL,
              INDEX idx_visit_day (day),
              UNIQUE INDEX uniq_visit_visitor_day (visitor_hash, day),
              PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE webhook_event (
              id INT AUTO_INCREMENT NOT NULL,
              channel VARCHAR(40) NOT NULL,
              provider VARCHAR(40) NOT NULL,
              event_id VARCHAR(191) NOT NULL,
              type VARCHAR(100) NOT NULL,
              payload JSON NOT NULL,
              status VARCHAR(20) NOT NULL,
              error LONGTEXT DEFAULT NULL,
              received_at DATETIME NOT NULL,
              processed_at DATETIME DEFAULT NULL,
              UNIQUE INDEX uniq_webhook_provider_event (provider, event_id),
              PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE messenger_messages (
              id BIGINT AUTO_INCREMENT NOT NULL,
              body LONGTEXT NOT NULL,
              headers LONGTEXT NOT NULL,
              queue_name VARCHAR(190) NOT NULL,
              created_at DATETIME NOT NULL,
              available_at DATETIME NOT NULL,
              delivered_at DATETIME DEFAULT NULL,
              INDEX IDX_75EA56E0FB7336F0E3BD61CE16BA31DBBF396750 (
                queue_name, available_at, delivered_at,
                id
              ),
              PRIMARY KEY (id)
            )
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              admin_profile
            ADD
              CONSTRAINT FK_456B2886A76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              agent_run
            ADD
              CONSTRAINT FK_AC4401AA166D1F9C FOREIGN KEY (project_id) REFERENCES project (id) ON DELETE CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              agent_run
            ADD
              CONSTRAINT FK_AC4401AA1BA80DE3 FOREIGN KEY (project_task_id) REFERENCES project_task (id) ON DELETE CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              agent_task
            ADD
              CONSTRAINT FK_FFE561C684E3FEC4 FOREIGN KEY (run_id) REFERENCES agent_run (id) ON DELETE CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              agent_task
            ADD
              CONSTRAINT FK_FFE561C6166D1F9C FOREIGN KEY (project_id) REFERENCES project (id) ON DELETE CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              api_token
            ADD
              CONSTRAINT FK_7BA2F5EBA76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              conversation
            ADD
              CONSTRAINT FK_8A8E26E955458D FOREIGN KEY (lead_id) REFERENCES sales_lead (id) ON DELETE
            SET
              NULL
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              conversation
            ADD
              CONSTRAINT FK_8A8E26E99395C3F3 FOREIGN KEY (customer_id) REFERENCES customer (id) ON DELETE
            SET
              NULL
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              conversation_message
            ADD
              CONSTRAINT FK_2DEB3E759AC0396 FOREIGN KEY (conversation_id) REFERENCES conversation (id) ON DELETE CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              customer
            ADD
              CONSTRAINT FK_81398E09A76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              customer_order
            ADD
              CONSTRAINT FK_3B1CE6A39395C3F3 FOREIGN KEY (customer_id) REFERENCES customer (id) ON DELETE RESTRICT
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              customer_order
            ADD
              CONSTRAINT FK_3B1CE6A3166D1F9C FOREIGN KEY (project_id) REFERENCES project (id) ON DELETE RESTRICT
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              customer_order
            ADD
              CONSTRAINT FK_3B1CE6A3DB805178 FOREIGN KEY (quote_id) REFERENCES quote (id) ON DELETE RESTRICT
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              deployment
            ADD
              CONSTRAINT FK_EB1255BE166D1F9C FOREIGN KEY (project_id) REFERENCES project (id) ON DELETE CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              domain
            ADD
              CONSTRAINT FK_A7A91E0B166D1F9C FOREIGN KEY (project_id) REFERENCES project (id) ON DELETE
            SET
              NULL
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              hosting_account
            ADD
              CONSTRAINT FK_10B6033E166D1F9C FOREIGN KEY (project_id) REFERENCES project (id) ON DELETE CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              hosting_account
            ADD
              CONSTRAINT FK_10B6033EE899029B FOREIGN KEY (plan_id) REFERENCES hosting_plan (id) ON DELETE
            SET
              NULL
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              hosting_deployment
            ADD
              CONSTRAINT FK_31E242C4419B48C0 FOREIGN KEY (hosting_account_id) REFERENCES hosting_account (id) ON DELETE CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              hosting_deployment
            ADD
              CONSTRAINT FK_31E242C4166D1F9C FOREIGN KEY (project_id) REFERENCES project (id) ON DELETE CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              hosting_plan
            ADD
              CONSTRAINT FK_61177D94A53A8AA FOREIGN KEY (provider_id) REFERENCES provider (id) ON DELETE CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              invoice
            ADD
              CONSTRAINT FK_906517448D9F6D38 FOREIGN KEY (order_id) REFERENCES customer_order (id) ON DELETE RESTRICT
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              invoice
            ADD
              CONSTRAINT FK_906517449A1887DC FOREIGN KEY (subscription_id) REFERENCES subscription (id) ON DELETE
            SET
              NULL
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              invoice
            ADD
              CONSTRAINT FK_906517449395C3F3 FOREIGN KEY (customer_id) REFERENCES customer (id) ON DELETE RESTRICT
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              lead_activity
            ADD
              CONSTRAINT FK_6278B5955458D FOREIGN KEY (lead_id) REFERENCES sales_lead (id) ON DELETE CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              notification
            ADD
              CONSTRAINT FK_BF5476CAE92F8F78 FOREIGN KEY (recipient_id) REFERENCES app_user (id) ON DELETE CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              notification
            ADD
              CONSTRAINT FK_BF5476CA166D1F9C FOREIGN KEY (project_id) REFERENCES project (id) ON DELETE CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              order_item
            ADD
              CONSTRAINT FK_52EA1F098D9F6D38 FOREIGN KEY (order_id) REFERENCES customer_order (id) ON DELETE CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              payment
            ADD
              CONSTRAINT FK_6D28840D8D9F6D38 FOREIGN KEY (order_id) REFERENCES customer_order (id) ON DELETE RESTRICT
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              project
            ADD
              CONSTRAINT FK_2FB3D0EE55458D FOREIGN KEY (lead_id) REFERENCES sales_lead (id) ON DELETE
            SET
              NULL
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              project
            ADD
              CONSTRAINT FK_2FB3D0EE9395C3F3 FOREIGN KEY (customer_id) REFERENCES customer (id) ON DELETE
            SET
              NULL
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              project
            ADD
              CONSTRAINT FK_2FB3D0EE7B576F77 FOREIGN KEY (requirement_id) REFERENCES requirement (id) ON DELETE RESTRICT
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              project
            ADD
              CONSTRAINT FK_2FB3D0EE1C0BE183 FOREIGN KEY (solution_id) REFERENCES solution (id) ON DELETE
            SET
              NULL
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              project
            ADD
              CONSTRAINT FK_2FB3D0EE46F82B52 FOREIGN KEY (current_quote_id) REFERENCES quote (id) ON DELETE
            SET
              NULL
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              project
            ADD
              CONSTRAINT FK_2FB3D0EE2D234F6A FOREIGN KEY (approved_by_id) REFERENCES app_user (id) ON DELETE
            SET
              NULL
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              project_cost_entry
            ADD
              CONSTRAINT FK_68055046166D1F9C FOREIGN KEY (project_id) REFERENCES project (id) ON DELETE CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              project_credential
            ADD
              CONSTRAINT FK_1DD96679166D1F9C FOREIGN KEY (project_id) REFERENCES project (id) ON DELETE CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              project_event
            ADD
              CONSTRAINT FK_28FB0339166D1F9C FOREIGN KEY (project_id) REFERENCES project (id) ON DELETE CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              project_task
            ADD
              CONSTRAINT FK_6BEF133D166D1F9C FOREIGN KEY (project_id) REFERENCES project (id) ON DELETE CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              provider_credential
            ADD
              CONSTRAINT FK_20C951F3A53A8AA FOREIGN KEY (provider_id) REFERENCES provider (id) ON DELETE CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              question
            ADD
              CONSTRAINT FK_B6F7494EDE95C867 FOREIGN KEY (sector_id) REFERENCES sector (id) ON DELETE CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              quote
            ADD
              CONSTRAINT FK_6B71CBF4166D1F9C FOREIGN KEY (project_id) REFERENCES project (id) ON DELETE CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              quote
            ADD
              CONSTRAINT FK_6B71CBF47B576F77 FOREIGN KEY (requirement_id) REFERENCES requirement (id) ON DELETE CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              quote
            ADD
              CONSTRAINT FK_6B71CBF41C0BE183 FOREIGN KEY (solution_id) REFERENCES solution (id) ON DELETE RESTRICT
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              quote
            ADD
              CONSTRAINT FK_6B71CBF485195701 FOREIGN KEY (hosting_plan_id) REFERENCES hosting_plan (id) ON DELETE
            SET
              NULL
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              quote
            ADD
              CONSTRAINT FK_6B71CBF49B8CE200 FOREIGN KEY (subscription_plan_id) REFERENCES subscription_plan (id) ON DELETE
            SET
              NULL
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              quote_item
            ADD
              CONSTRAINT FK_8DFC7A94DB805178 FOREIGN KEY (quote_id) REFERENCES quote (id) ON DELETE CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              requirement
            ADD
              CONSTRAINT FK_DB3F555055458D FOREIGN KEY (lead_id) REFERENCES sales_lead (id) ON DELETE
            SET
              NULL
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              requirement
            ADD
              CONSTRAINT FK_DB3F55509395C3F3 FOREIGN KEY (customer_id) REFERENCES customer (id) ON DELETE
            SET
              NULL
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              requirement
            ADD
              CONSTRAINT FK_DB3F55509AC0396 FOREIGN KEY (conversation_id) REFERENCES conversation (id) ON DELETE
            SET
              NULL
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              requirement
            ADD
              CONSTRAINT FK_DB3F55503AC56173 FOREIGN KEY (recommended_solution_id) REFERENCES solution (id) ON DELETE
            SET
              NULL
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              requirement_item
            ADD
              CONSTRAINT FK_CB05DB277B576F77 FOREIGN KEY (requirement_id) REFERENCES requirement (id) ON DELETE CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              sales_lead
            ADD
              CONSTRAINT FK_D5068C1E9395C3F3 FOREIGN KEY (customer_id) REFERENCES customer (id) ON DELETE
            SET
              NULL
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              solution_feature
            ADD
              CONSTRAINT FK_BB7547DC1C0BE183 FOREIGN KEY (solution_id) REFERENCES solution (id) ON DELETE CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              subscription
            ADD
              CONSTRAINT FK_A3C664D39395C3F3 FOREIGN KEY (customer_id) REFERENCES customer (id) ON DELETE RESTRICT
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              subscription
            ADD
              CONSTRAINT FK_A3C664D3166D1F9C FOREIGN KEY (project_id) REFERENCES project (id) ON DELETE
            SET
              NULL
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              subscription
            ADD
              CONSTRAINT FK_A3C664D3E899029B FOREIGN KEY (plan_id) REFERENCES subscription_plan (id) ON DELETE RESTRICT
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              test_result
            ADD
              CONSTRAINT FK_84B3C63D133AF9EA FOREIGN KEY (test_run_id) REFERENCES test_run (id) ON DELETE CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              test_run
            ADD
              CONSTRAINT FK_290B7807166D1F9C FOREIGN KEY (project_id) REFERENCES project (id) ON DELETE CASCADE
        SQL);
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE admin_profile DROP FOREIGN KEY FK_456B2886A76ED395');
        $this->addSql('ALTER TABLE agent_run DROP FOREIGN KEY FK_AC4401AA166D1F9C');
        $this->addSql('ALTER TABLE agent_run DROP FOREIGN KEY FK_AC4401AA1BA80DE3');
        $this->addSql('ALTER TABLE agent_task DROP FOREIGN KEY FK_FFE561C684E3FEC4');
        $this->addSql('ALTER TABLE agent_task DROP FOREIGN KEY FK_FFE561C6166D1F9C');
        $this->addSql('ALTER TABLE api_token DROP FOREIGN KEY FK_7BA2F5EBA76ED395');
        $this->addSql('ALTER TABLE conversation DROP FOREIGN KEY FK_8A8E26E955458D');
        $this->addSql('ALTER TABLE conversation DROP FOREIGN KEY FK_8A8E26E99395C3F3');
        $this->addSql('ALTER TABLE conversation_message DROP FOREIGN KEY FK_2DEB3E759AC0396');
        $this->addSql('ALTER TABLE customer DROP FOREIGN KEY FK_81398E09A76ED395');
        $this->addSql('ALTER TABLE customer_order DROP FOREIGN KEY FK_3B1CE6A39395C3F3');
        $this->addSql('ALTER TABLE customer_order DROP FOREIGN KEY FK_3B1CE6A3166D1F9C');
        $this->addSql('ALTER TABLE customer_order DROP FOREIGN KEY FK_3B1CE6A3DB805178');
        $this->addSql('ALTER TABLE deployment DROP FOREIGN KEY FK_EB1255BE166D1F9C');
        $this->addSql('ALTER TABLE domain DROP FOREIGN KEY FK_A7A91E0B166D1F9C');
        $this->addSql('ALTER TABLE hosting_account DROP FOREIGN KEY FK_10B6033E166D1F9C');
        $this->addSql('ALTER TABLE hosting_account DROP FOREIGN KEY FK_10B6033EE899029B');
        $this->addSql('ALTER TABLE hosting_deployment DROP FOREIGN KEY FK_31E242C4419B48C0');
        $this->addSql('ALTER TABLE hosting_deployment DROP FOREIGN KEY FK_31E242C4166D1F9C');
        $this->addSql('ALTER TABLE hosting_plan DROP FOREIGN KEY FK_61177D94A53A8AA');
        $this->addSql('ALTER TABLE invoice DROP FOREIGN KEY FK_906517448D9F6D38');
        $this->addSql('ALTER TABLE invoice DROP FOREIGN KEY FK_906517449A1887DC');
        $this->addSql('ALTER TABLE invoice DROP FOREIGN KEY FK_906517449395C3F3');
        $this->addSql('ALTER TABLE lead_activity DROP FOREIGN KEY FK_6278B5955458D');
        $this->addSql('ALTER TABLE notification DROP FOREIGN KEY FK_BF5476CAE92F8F78');
        $this->addSql('ALTER TABLE notification DROP FOREIGN KEY FK_BF5476CA166D1F9C');
        $this->addSql('ALTER TABLE order_item DROP FOREIGN KEY FK_52EA1F098D9F6D38');
        $this->addSql('ALTER TABLE payment DROP FOREIGN KEY FK_6D28840D8D9F6D38');
        $this->addSql('ALTER TABLE project DROP FOREIGN KEY FK_2FB3D0EE55458D');
        $this->addSql('ALTER TABLE project DROP FOREIGN KEY FK_2FB3D0EE9395C3F3');
        $this->addSql('ALTER TABLE project DROP FOREIGN KEY FK_2FB3D0EE7B576F77');
        $this->addSql('ALTER TABLE project DROP FOREIGN KEY FK_2FB3D0EE1C0BE183');
        $this->addSql('ALTER TABLE project DROP FOREIGN KEY FK_2FB3D0EE46F82B52');
        $this->addSql('ALTER TABLE project DROP FOREIGN KEY FK_2FB3D0EE2D234F6A');
        $this->addSql('ALTER TABLE project_cost_entry DROP FOREIGN KEY FK_68055046166D1F9C');
        $this->addSql('ALTER TABLE project_credential DROP FOREIGN KEY FK_1DD96679166D1F9C');
        $this->addSql('ALTER TABLE project_event DROP FOREIGN KEY FK_28FB0339166D1F9C');
        $this->addSql('ALTER TABLE project_task DROP FOREIGN KEY FK_6BEF133D166D1F9C');
        $this->addSql('ALTER TABLE provider_credential DROP FOREIGN KEY FK_20C951F3A53A8AA');
        $this->addSql('ALTER TABLE question DROP FOREIGN KEY FK_B6F7494EDE95C867');
        $this->addSql('ALTER TABLE quote DROP FOREIGN KEY FK_6B71CBF4166D1F9C');
        $this->addSql('ALTER TABLE quote DROP FOREIGN KEY FK_6B71CBF47B576F77');
        $this->addSql('ALTER TABLE quote DROP FOREIGN KEY FK_6B71CBF41C0BE183');
        $this->addSql('ALTER TABLE quote DROP FOREIGN KEY FK_6B71CBF485195701');
        $this->addSql('ALTER TABLE quote DROP FOREIGN KEY FK_6B71CBF49B8CE200');
        $this->addSql('ALTER TABLE quote_item DROP FOREIGN KEY FK_8DFC7A94DB805178');
        $this->addSql('ALTER TABLE requirement DROP FOREIGN KEY FK_DB3F555055458D');
        $this->addSql('ALTER TABLE requirement DROP FOREIGN KEY FK_DB3F55509395C3F3');
        $this->addSql('ALTER TABLE requirement DROP FOREIGN KEY FK_DB3F55509AC0396');
        $this->addSql('ALTER TABLE requirement DROP FOREIGN KEY FK_DB3F55503AC56173');
        $this->addSql('ALTER TABLE requirement_item DROP FOREIGN KEY FK_CB05DB277B576F77');
        $this->addSql('ALTER TABLE sales_lead DROP FOREIGN KEY FK_D5068C1E9395C3F3');
        $this->addSql('ALTER TABLE solution_feature DROP FOREIGN KEY FK_BB7547DC1C0BE183');
        $this->addSql('ALTER TABLE subscription DROP FOREIGN KEY FK_A3C664D39395C3F3');
        $this->addSql('ALTER TABLE subscription DROP FOREIGN KEY FK_A3C664D3166D1F9C');
        $this->addSql('ALTER TABLE subscription DROP FOREIGN KEY FK_A3C664D3E899029B');
        $this->addSql('ALTER TABLE test_result DROP FOREIGN KEY FK_84B3C63D133AF9EA');
        $this->addSql('ALTER TABLE test_run DROP FOREIGN KEY FK_290B7807166D1F9C');
        $this->addSql('DROP TABLE admin_profile');
        $this->addSql('DROP TABLE agent');
        $this->addSql('DROP TABLE agent_run');
        $this->addSql('DROP TABLE agent_task');
        $this->addSql('DROP TABLE api_token');
        $this->addSql('DROP TABLE app_user');
        $this->addSql('DROP TABLE audit_log');
        $this->addSql('DROP TABLE conversation');
        $this->addSql('DROP TABLE conversation_message');
        $this->addSql('DROP TABLE customer');
        $this->addSql('DROP TABLE customer_order');
        $this->addSql('DROP TABLE deployment');
        $this->addSql('DROP TABLE domain');
        $this->addSql('DROP TABLE hosting_account');
        $this->addSql('DROP TABLE hosting_deployment');
        $this->addSql('DROP TABLE hosting_plan');
        $this->addSql('DROP TABLE invoice');
        $this->addSql('DROP TABLE lead_activity');
        $this->addSql('DROP TABLE mock_provider_resource');
        $this->addSql('DROP TABLE notification');
        $this->addSql('DROP TABLE order_item');
        $this->addSql('DROP TABLE payment');
        $this->addSql('DROP TABLE project');
        $this->addSql('DROP TABLE project_cost_entry');
        $this->addSql('DROP TABLE project_credential');
        $this->addSql('DROP TABLE project_event');
        $this->addSql('DROP TABLE project_task');
        $this->addSql('DROP TABLE provider');
        $this->addSql('DROP TABLE provider_credential');
        $this->addSql('DROP TABLE question');
        $this->addSql('DROP TABLE quote');
        $this->addSql('DROP TABLE quote_item');
        $this->addSql('DROP TABLE requirement');
        $this->addSql('DROP TABLE requirement_item');
        $this->addSql('DROP TABLE sales_lead');
        $this->addSql('DROP TABLE sector');
        $this->addSql('DROP TABLE setting');
        $this->addSql('DROP TABLE solution');
        $this->addSql('DROP TABLE solution_feature');
        $this->addSql('DROP TABLE subscription');
        $this->addSql('DROP TABLE subscription_plan');
        $this->addSql('DROP TABLE test_result');
        $this->addSql('DROP TABLE test_run');
        $this->addSql('DROP TABLE visit');
        $this->addSql('DROP TABLE webhook_event');
        $this->addSql('DROP TABLE messenger_messages');
    }
}
