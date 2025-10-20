/**
 * Address validation and formatting utilities
 * Helps ensure addresses are complete and properly formatted
 */

export interface AddressComponents {
    street?: string;
    city?: string;
    state?: string;
    zip?: string;
}

export interface AddressValidationResult {
    isValid: boolean;
    score: number; // 0-100, higher is better
    issues: string[];
    suggestions: string[];
}

export class AddressValidator {
    
    /**
     * Parse address string into components
     */
    static parseAddress(address: string): AddressComponents {
        const parts = address.split(',').map(part => part.trim());
        
        return {
            street: parts[0] || '',
            city: parts[1] || '',
            state: parts[2] || '',
            zip: parts[3] || '',
        };
    }

    /**
     * Validate address completeness and format
     */
    static validateAddress(address: string): AddressValidationResult {
        const issues: string[] = [];
        const suggestions: string[] = [];
        let score = 0;

        if (!address || address.trim().length === 0) {
            return {
                isValid: false,
                score: 0,
                issues: ['Address is required'],
                suggestions: ['Enter a complete address'],
            };
        }

        const components = this.parseAddress(address);
        
        // Check street address
        if (!components.street || components.street.length < 3) {
            issues.push('Street address is too short');
            suggestions.push('Include house number and street name');
        } else {
            score += 30;
            
            // Check if it has a number
            if (!/\d/.test(components.street)) {
                issues.push('Street address should include a house number');
                suggestions.push('Add house number (e.g., "123 Main St")');
            } else {
                score += 10;
            }
        }

        // Check city
        if (!components.city || components.city.length < 2) {
            issues.push('City is missing or too short');
            suggestions.push('Include city name');
        } else {
            score += 25;
        }

        // Check state
        if (!components.state || components.state.length < 2) {
            issues.push('State is missing');
            suggestions.push('Include state (e.g., "CT")');
        } else {
            score += 20;
            
            // Check if it's a valid CT format
            if (components.state.toUpperCase() === 'CT' || 
                components.state.toLowerCase() === 'connecticut') {
                score += 5;
            }
        }

        // Check ZIP code
        if (!components.zip) {
            issues.push('ZIP code is missing');
            suggestions.push('Include ZIP code');
        } else if (!/^\d{5}(-\d{4})?$/.test(components.zip)) {
            issues.push('ZIP code format is invalid');
            suggestions.push('Use 5-digit ZIP (e.g., "06489")');
        } else {
            score += 15;
        }

        // Additional checks
        if (address.length < 15) {
            score -= 10;
            suggestions.push('Address seems too short - include full details');
        }

        const isValid = issues.length === 0 && score >= 70;

        return {
            isValid,
            score: Math.max(0, Math.min(100, score)),
            issues,
            suggestions,
        };
    }

    /**
     * Format address for consistent display
     */
    static formatAddress(address: string): string {
        const components = this.parseAddress(address);
        
        const parts = [
            components.street,
            components.city,
            components.state?.toUpperCase(),
            components.zip
        ].filter(part => part && part.trim().length > 0);

        return parts.join(', ');
    }

    /**
     * Check if address is likely in Connecticut
     */
    static isConnecticutAddress(address: string): boolean {
        const upperAddress = address.toUpperCase();
        return upperAddress.includes('CT') || 
               upperAddress.includes('CONNECTICUT') ||
               /\b064\d{2}\b/.test(address); // CT ZIP codes start with 064
    }

    /**
     * Get address quality description
     */
    static getQualityDescription(score: number): string {
        if (score >= 90) return 'Excellent';
        if (score >= 75) return 'Good';
        if (score >= 60) return 'Fair';
        if (score >= 40) return 'Poor';
        return 'Incomplete';
    }
}