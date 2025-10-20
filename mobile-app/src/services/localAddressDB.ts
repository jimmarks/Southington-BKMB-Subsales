import AsyncStorage from '@react-native-async-storage/async-storage';

interface AddressDatabase {
    street: string;
    city: string;
    state: string;
    zip: string;
    fullAddress: string;
}

const LOCAL_ADDRESSES_KEY = '@local_address_db';

/**
 * Local address database for offline address suggestions
 * Pre-populate with common addresses in your service area
 */
export class LocalAddressDB {
    private static addresses: AddressDatabase[] = [
        // Southington, CT Common Locations
        
        // Schools
        { street: '720 Pleasant St', city: 'Southington', state: 'CT', zip: '06489', fullAddress: '720 Pleasant St, Southington, CT 06489' },
        { street: '1 John Barry Elementary School', city: 'Southington', state: 'CT', zip: '06489', fullAddress: '1 John Barry Elementary School, Southington, CT 06489' },
        { street: '75 Scotland Rd', city: 'Southington', state: 'CT', zip: '06489', fullAddress: '75 Scotland Rd, Southington, CT 06489' },
        
        // Municipal Buildings
        { street: '75 Main St', city: 'Southington', state: 'CT', zip: '06489', fullAddress: '75 Main St, Southington, CT 06489' },
        { street: '200 North Main St', city: 'Southington', state: 'CT', zip: '06489', fullAddress: '200 North Main St, Southington, CT 06489' },
        
        // Shopping Centers
        { street: '900 Queen St', city: 'Southington', state: 'CT', zip: '06489', fullAddress: '900 Queen St, Southington, CT 06489' },
        { street: '1067 Queen St', city: 'Southington', state: 'CT', zip: '06489', fullAddress: '1067 Queen St, Southington, CT 06489' },
        { street: '850 Queen St', city: 'Southington', state: 'CT', zip: '06489', fullAddress: '850 Queen St, Southington, CT 06489' },
        
        // Churches
        { street: '90 Main St', city: 'Southington', state: 'CT', zip: '06489', fullAddress: '90 Main St, Southington, CT 06489' },
        { street: '1 Central St', city: 'Southington', state: 'CT', zip: '06489', fullAddress: '1 Central St, Southington, CT 06489' },
        
        // Recreation
        { street: '480 Spring St', city: 'Southington', state: 'CT', zip: '06489', fullAddress: '480 Spring St, Southington, CT 06489' },
        { street: '166 Savage St', city: 'Southington', state: 'CT', zip: '06489', fullAddress: '166 Savage St, Southington, CT 06489' },
        
        // Healthcare
        { street: '1579 Meriden Ave', city: 'Southington', state: 'CT', zip: '06489', fullAddress: '1579 Meriden Ave, Southington, CT 06489' },
        
        // Common Neighborhoods - Main Streets
        { street: '123 Main St', city: 'Southington', state: 'CT', zip: '06489', fullAddress: '123 Main St, Southington, CT 06489' },
        { street: '456 Queen St', city: 'Southington', state: 'CT', zip: '06489', fullAddress: '456 Queen St, Southington, CT 06489' },
        { street: '789 West St', city: 'Southington', state: 'CT', zip: '06489', fullAddress: '789 West St, Southington, CT 06489' },
        { street: '321 Spring St', city: 'Southington', state: 'CT', zip: '06489', fullAddress: '321 Spring St, Southington, CT 06489' },
        { street: '654 Meriden Ave', city: 'Southington', state: 'CT', zip: '06489', fullAddress: '654 Meriden Ave, Southington, CT 06489' },
        { street: '987 Pleasant St', city: 'Southington', state: 'CT', zip: '06489', fullAddress: '987 Pleasant St, Southington, CT 06489' },
        
        // Neighboring towns (common delivery areas)
        { street: '123 Main St', city: 'Wallingford', state: 'CT', zip: '06492', fullAddress: '123 Main St, Wallingford, CT 06492' },
        { street: '456 Broad St', city: 'Meriden', state: 'CT', zip: '06450', fullAddress: '456 Broad St, Meriden, CT 06450' },
        { street: '789 Main St', city: 'Berlin', state: 'CT', zip: '06037', fullAddress: '789 Main St, Berlin, CT 06037' },
        { street: '321 Elm St', city: 'Cromwell', state: 'CT', zip: '06416', fullAddress: '321 Elm St, Cromwell, CT 06416' },
    ];

    static async loadAddresses(): Promise<AddressDatabase[]> {
        try {
            const stored = await AsyncStorage.getItem(LOCAL_ADDRESSES_KEY);
            if (stored) {
                const parsed = JSON.parse(stored);
                // Merge with default addresses
                return [...this.addresses, ...parsed];
            }
            return this.addresses;
        } catch (error) {
            console.warn('Failed to load local addresses:', error);
            return this.addresses;
        }
    }

    static async addAddress(address: AddressDatabase): Promise<void> {
        try {
            const current = await this.loadAddresses();
            const updated = [...current, address];
            await AsyncStorage.setItem(LOCAL_ADDRESSES_KEY, JSON.stringify(updated));
        } catch (error) {
            console.warn('Failed to save address:', error);
        }
    }

    static async searchAddresses(query: string): Promise<AddressDatabase[]> {
        const addresses = await this.loadAddresses();
        const lowerQuery = query.toLowerCase();
        
        return addresses.filter(addr =>
            addr.fullAddress.toLowerCase().includes(lowerQuery) ||
            addr.street.toLowerCase().includes(lowerQuery) ||
            addr.city.toLowerCase().includes(lowerQuery)
        ).slice(0, 10);
    }
}