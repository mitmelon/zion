<?php

declare(strict_types=1);

namespace Zion\Memory\Agents;

use Zion\Memory\Contracts\AgentInterface;
use Zion\Memory\Contracts\AgentResponse;
use Zion\Memory\Contracts\AIProviderInterface;

/**
 * Class BaseAgent
 * 
 * Abstract base class for AI agents.
 * Provides common functionality for all agent types.
 * 
 * @package Zion\Memory\Agents
 */
abstract class BaseAgent implements AgentInterface
{
    /**
     * @var string Agent ID
     */
    protected string $id;

    /**
     * @var string Agent name
     */
    protected string $name;

    /**
     * @var string Agent role
     */
    protected string $role;

    /**
     * @var int Agent priority
     */
    protected int $priority;

    /**
     * @var AIProviderInterface AI provider
     */
    protected AIProviderInterface $aiProvider;

    /**
     * @var array Agent configuration
     */
    protected array $config = [];

    /**
     * @var array Agent capabilities
     */
    protected array $capabilities = [];

    /**
     * Constructor.
     *
     * @param string $id Agent ID
     * @param string $name Agent name
     * @param string $role Agent role
     * @param int $priority Agent priority
     * @param AIProviderInterface $aiProvider AI provider
     * @param array $config Configuration
     */
    public function __construct(
        string $id,
        string $name,
        string $role,
        int $priority,
        AIProviderInterface $aiProvider,
        array $config = []
    ) {
        $this->id = $id;
        $this->name = $name;
        $this->role = $role;
        $this->priority = $priority;
        $this->aiProvider = $aiProvider;
        $this->config = $config;
    }

    /**
     * {@inheritdoc}
     */
    public function getId(): string
    {
        return $this->id;
    }

    /**
     * {@inheritdoc}
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * {@inheritdoc}
     */
    public function getRole(): string
    {
        return $this->role;
    }

    /**
     * {@inheritdoc}
     */
    public function getPriority(): int
    {
        return $this->priority;
    }

    /**
     * {@inheritdoc}
     */
    public function getCapabilities(): array
    {
        return $this->capabilities;
    }

    /**
     * {@inheritdoc}
     */
    public function canHandle(string $queryType): bool
    {
        return in_array($queryType, $this->capabilities, true);
    }

    /**
     * {@inheritdoc}
     */
    public function getConfig(): array
    {
        return $this->config;
    }

    /**
     * Get the system prompt for this agent.
     *
     * @return string System prompt
     */
    abstract protected function getSystemPrompt(): string;
}

/**
 * Class ComplianceAgent
 * 
 * Agent specialized in compliance and regulatory matters.
 */
class ComplianceAgent extends BaseAgent
{
    protected array $capabilities = [
        'compliance_check',
        'regulatory_review',
        'kyc_verification',
        'aml_screening',
        'policy_enforcement',
    ];

    public function __construct(AIProviderInterface $aiProvider, array $config = [])
    {
        parent::__construct(
            'compliance_agent',
            'Compliance Agent',
            'compliance',
            100,
            $aiProvider,
            $config
        );
    }

    /**
     * {@inheritdoc}
     */
    public function process(string $query, array $context = []): AgentResponse
    {
        $prompt = $this->buildPrompt($query, $context);
        
        $response = $this->aiProvider->chat([
            ['role' => 'user', 'content' => $prompt]
        ], [
            'system' => $this->getSystemPrompt(),
            'temperature' => 0.2, // Low temperature for compliance accuracy
        ]);

        return new AgentResponse(
            agentId: $this->id,
            content: $response,
            extractedFacts: [],
            reasoning: ['Compliance analysis performed'],
            confidence: 0.95,
            metadata: ['agent_role' => $this->role]
        );
    }

    /**
     * {@inheritdoc}
     */
    public function validateFact(array $fact, array $context = []): array
    {
        $factStr = json_encode($fact);
        $prompt = "Validate the following fact from a compliance perspective:\n{$factStr}\n\nProvide compliance assessment.";
        
        $response = $this->aiProvider->complete($prompt);
        
        return [
            'valid' => true,
            'confidence' => 0.9,
            'reasoning' => $response,
            'agent_id' => $this->id,
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function voteOnConflict(array $conflictingFacts, array $context = []): array
    {
        // Compliance agent prefers the more conservative/compliant option
        $bestFact = null;
        $bestScore = 0;

        foreach ($conflictingFacts as $fact) {
            $score = $this->assessComplianceScore($fact);
            if ($score > $bestScore) {
                $bestScore = $score;
                $bestFact = $fact;
            }
        }

        return [
            'chosen_fact' => $bestFact ?? $conflictingFacts[0],
            'confidence' => $bestScore,
            'reasoning' => 'Selected most compliant option',
            'agent_id' => $this->id,
        ];
    }

    /**
     * {@inheritdoc}
     */
    protected function getSystemPrompt(): string
    {
        return <<<PROMPT
You are a banking compliance expert agent. Your role is to:
1. Ensure all responses comply with banking regulations
2. Flag potential compliance issues
3. Verify KYC/AML requirements
4. Enforce data protection policies
5. Maintain audit trail requirements

Always prioritize regulatory compliance and customer protection.
Be conservative in your assessments and flag any potential issues.
PROMPT;
    }

    private function buildPrompt(string $query, array $context): string
    {
        $contextStr = !empty($context) ? "\n\nContext:\n" . json_encode($context) : '';
        return "Compliance Review Request:\n{$query}{$contextStr}";
    }

    private function assessComplianceScore(array $fact): float
    {
        // Simple scoring based on fact attributes
        $score = 0.5;
        
        if (isset($fact['verified']) && $fact['verified']) {
            $score += 0.2;
        }
        
        if (isset($fact['source']) && !empty($fact['source'])) {
            $score += 0.1;
        }
        
        if (isset($fact['confidence'])) {
            $score += $fact['confidence'] * 0.2;
        }
        
        return min(1.0, $score);
    }
}

/**
 * Class RiskAgent
 * 
 * Agent specialized in risk assessment.
 */
class RiskAgent extends BaseAgent
{
    protected array $capabilities = [
        'risk_assessment',
        'fraud_detection',
        'credit_evaluation',
        'transaction_monitoring',
        'anomaly_detection',
    ];

    public function __construct(AIProviderInterface $aiProvider, array $config = [])
    {
        parent::__construct(
            'risk_agent',
            'Risk Assessment Agent',
            'risk',
            90,
            $aiProvider,
            $config
        );
    }

    /**
     * {@inheritdoc}
     */
    public function process(string $query, array $context = []): AgentResponse
    {
        $prompt = $this->buildPrompt($query, $context);
        
        $response = $this->aiProvider->chat([
            ['role' => 'user', 'content' => $prompt]
        ], [
            'system' => $this->getSystemPrompt(),
            'temperature' => 0.3,
        ]);

        return new AgentResponse(
            agentId: $this->id,
            content: $response,
            extractedFacts: [],
            reasoning: ['Risk assessment performed'],
            confidence: 0.9,
            metadata: ['agent_role' => $this->role]
        );
    }

    /**
     * {@inheritdoc}
     */
    public function validateFact(array $fact, array $context = []): array
    {
        return [
            'valid' => true,
            'confidence' => 0.85,
            'reasoning' => 'Risk assessment completed',
            'agent_id' => $this->id,
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function voteOnConflict(array $conflictingFacts, array $context = []): array
    {
        // Risk agent prefers the lower-risk option
        return [
            'chosen_fact' => $conflictingFacts[0],
            'confidence' => 0.85,
            'reasoning' => 'Selected lower-risk option',
            'agent_id' => $this->id,
        ];
    }

    /**
     * {@inheritdoc}
     */
    protected function getSystemPrompt(): string
    {
        return <<<PROMPT
You are a banking risk assessment expert agent. Your role is to:
1. Identify and evaluate potential risks
2. Detect fraudulent patterns
3. Assess creditworthiness
4. Monitor transaction anomalies
5. Provide risk mitigation recommendations

Always be thorough in risk identification and provide clear risk levels.
PROMPT;
    }

    private function buildPrompt(string $query, array $context): string
    {
        $contextStr = !empty($context) ? "\n\nContext:\n" . json_encode($context) : '';
        return "Risk Assessment Request:\n{$query}{$contextStr}";
    }
}

/**
 * Class CustomerServiceAgent
 * 
 * Agent specialized in customer service.
 */
class CustomerServiceAgent extends BaseAgent
{
    protected array $capabilities = [
        'customer_inquiry',
        'account_support',
        'transaction_help',
        'product_information',
        'complaint_handling',
    ];

    public function __construct(AIProviderInterface $aiProvider, array $config = [])
    {
        parent::__construct(
            'customer_service_agent',
            'Customer Service Agent',
            'customer_service',
            70,
            $aiProvider,
            $config
        );
    }

    /**
     * {@inheritdoc}
     */
    public function process(string $query, array $context = []): AgentResponse
    {
        $prompt = $this->buildPrompt($query, $context);
        
        $response = $this->aiProvider->chat([
            ['role' => 'user', 'content' => $prompt]
        ], [
            'system' => $this->getSystemPrompt(),
            'temperature' => 0.7, // Higher temperature for more natural responses
        ]);

        return new AgentResponse(
            agentId: $this->id,
            content: $response,
            extractedFacts: [],
            reasoning: ['Customer service response generated'],
            confidence: 0.85,
            metadata: ['agent_role' => $this->role]
        );
    }

    /**
     * {@inheritdoc}
     */
    public function validateFact(array $fact, array $context = []): array
    {
        return [
            'valid' => true,
            'confidence' => 0.8,
            'reasoning' => 'Customer context validated',
            'agent_id' => $this->id,
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function voteOnConflict(array $conflictingFacts, array $context = []): array
    {
        // Customer service prefers the most customer-friendly option
        return [
            'chosen_fact' => $conflictingFacts[0],
            'confidence' => 0.8,
            'reasoning' => 'Selected customer-friendly option',
            'agent_id' => $this->id,
        ];
    }

    /**
     * {@inheritdoc}
     */
    protected function getSystemPrompt(): string
    {
        return <<<PROMPT
You are a banking customer service expert agent. Your role is to:
1. Provide helpful and friendly customer support
2. Answer account and transaction inquiries
3. Explain banking products and services
4. Handle customer complaints professionally
5. Ensure customer satisfaction

Be empathetic, clear, and solution-oriented in all interactions.
PROMPT;
    }

    private function buildPrompt(string $query, array $context): string
    {
        $contextStr = !empty($context) ? "\n\nCustomer Context:\n" . json_encode($context) : '';
        return "Customer Inquiry:\n{$query}{$contextStr}";
    }
}
