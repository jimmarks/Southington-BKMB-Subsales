import React, { useState } from 'react';
import { TextInput, View, FlatList, Text, TouchableOpacity } from 'react-native';
import { GooglePlacesAutocomplete } from 'react-native-google-places-autocomplete';

const AddressAutocomplete = ({ onAddressSelect }) => {
    const [address, setAddress] = useState('');

    return (
        <View>
            <GooglePlacesAutocomplete
                placeholder='Enter address'
                onPress={(data, details = null) => {
                    setAddress(data.description);
                    onAddressSelect(data, details);
                }}
                query={{
                    key: 'YOUR_GOOGLE_MAPS_API_KEY',
                    language: 'en',
                }}
                styles={{
                    textInputContainer: {
                        backgroundColor: '#fff',
                        borderTopWidth: 0,
                        borderBottomWidth: 0,
                    },
                    textInput: {
                        height: 38,
                        color: '#5d5d5d',
                        fontSize: 16,
                    },
                    predefinedPlacesDescription: {
                        color: '#1faadb',
                    },
                }}
                debounce={200}
            />
            <Text>{address}</Text>
        </View>
    );
};

export default AddressAutocomplete;